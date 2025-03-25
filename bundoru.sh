#!/usr/bin/env bash

# Description:
# This script searches for all .yaml, .php files and composer.json within the current directory and its subdirectories,
# excluding the 'var' and 'vendor' directories and 'composer.lock'.
# It then empties the 'bundle' directory if it exists (or creates it if it doesn't),
# and copies all found files into the 'bundle' directory.
# It also creates a file with the original folder and file structure, excluding the bundle directory itself.

# Exit immediately if a command exits with a non-zero status
set -e

# Define the bundle directory name
BUNDLE_DIR="bundle"

# Function to display messages
echo_msg() {
    echo "[$(date +"%Y-%m-%d %H:%M:%S")] $1"
}

# Step 1: Remove all contents of the bundle directory if it exists, else create it
if [ -d "$BUNDLE_DIR" ]; then
    echo_msg "Emptying the '$BUNDLE_DIR' directory..."
    rm -rf "${BUNDLE_DIR:?}/"*
else
    echo_msg "Creating the '$BUNDLE_DIR' directory..."
    mkdir "$BUNDLE_DIR"
fi

# Step 2: Find all .yaml, .php files and composer.json excluding the var and vendor directories
echo_msg "Searching for .yaml, .php files and composer.json (excluding 'var' and 'vendor' directories)..."
TARGET_FILES=$(find . -path "./$BUNDLE_DIR" -prune -o -type f \( -name "*.yaml" -o -name "*.php" -o -name "composer.json" \) ! -path "*/var/*" ! -path "*/vendor/*" ! -name "composer.lock" -print)

# Check if any files were found
if [ -z "$TARGET_FILES" ]; then
    echo_msg "No matching files found."
    exit 0
fi

# Step 3: Copy each file to the bundle directory
echo_msg "Copying files to '$BUNDLE_DIR'..."
while IFS= read -r file; do
    # Get just the filename without the path
    filename=$(basename "$file")

    # If multiple files have the same name, append a counter to make the filename unique
    if [ -f "$BUNDLE_DIR/$filename" ]; then
        counter=1
        extension="${filename##*.}"
        name="${filename%.*}"

        while [ -f "$BUNDLE_DIR/${name}_${counter}.${extension}" ]; do
            counter=$((counter+1))
        done

        cp "$file" "$BUNDLE_DIR/${name}_${counter}.${extension}"
        echo_msg "Copied: $file -> ${name}_${counter}.${extension} (renamed to avoid conflicts)"
    else
        cp "$file" "$BUNDLE_DIR/"
        echo_msg "Copied: $file"
    fi
done <<< "$TARGET_FILES"

# Step 4: Create a file with the complete folder and file structure
echo_msg "Creating folder and file structure file..."
{
    echo "Directory structure with files:"
    echo "==============================="

    # Find all directories excluding var, vendor, bundle, and hidden dirs
    find . -path "./$BUNDLE_DIR" -prune -o -type d ! -path "*/var/*" ! -path "*/vendor/*" ! -path "*/\.*" -print | sort | while read -r dir; do
        # Print the directory name
        echo "📁 $dir"

        # Find and print files in this directory that match our criteria (yaml, php, composer.json)
        find "$dir" -maxdepth 1 -type f \( -name "*.yaml" -o -name "*.php" -o -name "composer.json" \) ! -name "composer.lock" 2>/dev/null | sort | while read -r file; do
            echo "   📄 $(basename "$file")"
        done
    done
} > "$BUNDLE_DIR/project_structure.txt"

echo_msg "All matching files have been bundled successfully in '$BUNDLE_DIR'."
echo_msg "Project structure has been saved to '$BUNDLE_DIR/project_structure.txt'."