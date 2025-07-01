# AllegroRedisOdmBundle

A Symfony bundle providing an Object Document Mapper (ODM) for Redis. This bundle simplifies storing, retrieving, and managing PHP objects in Redis with support for various Redis storage formats, indexing, automated object hydration, and tools for handling large datasets.

## Features

-   **Simple object persistence** - Store PHP objects directly in Redis.
-   **Multiple storage formats** - Store documents as Redis Hashes or JSON.
-   **Automatic indexing** - Create and maintain secondary indices (Redis Sets) for fast lookups.
-   **Sorted Indices & Range Queries** - Efficiently query numeric or string ranges using Redis Sorted Sets (via `#[SortedIndex]` and `RangeQuery` builder).
-   **Repository pattern** - Clean data access through document repositories.
-   **Attribute-based mapping** - Define document structure using PHP 8 attributes.
-   **TTL support** - Set expiration times for documents and indices.
-   **Multiple client support** - Works with both PhpRedis and Predis clients.
-   **Optimized for Large Datasets** - Utilizes Redis `SCAN` for key iteration and server-side operations (like `SINTERSTORE`) where appropriate to minimize memory overhead and improve performance.
-   **Batch Processing Utilities** - Provides `BatchProcessor` and `BulkOperations` services for efficient handling of large data volumes.
-   **Performance Analysis Tools** - Includes `allegro:analyze-performance` command to inspect collection statistics, memory usage, and benchmark common operations.
-   **Symfony integration** - Seamlessly integrates with the Symfony framework.

## Requirements

-   PHP 8.2 or higher
-   Symfony 6.0+ or 7.0+
-   Redis server
-   Either the PHP Redis extension (`ext-redis`) or `predis/predis` package

## Installation

### Step 1: Install the bundle

```bash
composer require phillarmonic/allegro-redis-odm-bundle
```

### Step 2: Enable the bundle in your kernel

```php
// config/bundles.php
return [
    // ...
    Phillarmonic\AllegroRedisOdmBundle\AllegroRedisOdmBundle::class => ['all' => true],
];
```

### Step 3: Configure the bundle

Create a configuration file at `config/packages/allegro_redis_odm.yaml`:

```yaml
allegro_redis_odm:
    client_type: phpredis  # Options: phpredis, predis
    connection:
        scheme: redis      # Options: redis, rediss (for TLS)
        host: 127.0.0.1
        port: 6379
        database: 0
        # auth: null       # Password if required
        # read_timeout: 0  # In seconds, 0 for no timeout
        # persistent: false
        # options: {}      # Client-specific options, e.g., for Predis SSL: { ssl: { verify_peer: true } }

    # Default storage settings
    default_storage:
        type: hash         # Options: hash, json
        ttl: 0             # Default TTL in seconds (0 = no expiration)

    # Document mappings
    mappings:
        app:
            dir: '%kernel.project_dir%/src/Document' # Directory containing your document classes
            namespace: 'App\Document'                # Base namespace for your document classes
            prefix: 'app'  # Optional global prefix for all Redis keys managed by this mapping
```

## Usage

### Defining Documents

Create document classes in your project (e.g., in `src/Document/`):

```php
<?php
// src/Document/Article.php
namespace App\Document;

use Phillarmonic\AllegroRedisOdmBundle\Mapping\Document;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\Field;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\Id;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\Index;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\SortedIndex;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\RedisHash;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\Expiration;

#[Document(collection: 'articles', prefix:'blog')] // Collection name & optional key prefix
#[RedisHash] // Store as Redis Hash (default if neither RedisHash nor RedisJson is specified)
#[Expiration(ttl: 3600)] // Optional: 1-hour expiration for all articles
class Article
{
    #[Id] // Auto-generated ID by default
    private ?string $id = null;

    #[Field]
    #[Index] // Create an index on the slug
    private string $slug;

    #[Field(name: 'title', nullable: false)]
    private string $title;

    #[Field(type: 'string', nullable: true)]
    private ?string $content = null;

    #[Field]
    #[Index] // Create an index on the category
    private string $category;

    #[Field(type: 'boolean')]
    private bool $isPublished = false;

    #[Field(type: 'integer')]
    #[SortedIndex] // Create a sorted index on viewCount for range queries
    private int $viewCount = 0;

    #[Field(type: 'datetime')]
    #[Index] // Index for querying by publication date
    #[SortedIndex(name: 'published_time_idx')] // Also a sorted index for date range queries
    private ?\DateTime $publishedAt = null;

    // --- Getters and Setters ---
    public function getId(): ?string { return $this->id; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): self { $this->slug = $slug; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = $title; return $this; }
    public function getContent(): ?string { return $this->content; }
    public function setContent(?string $content): self { $this->content = $content; return $this; }
    public function getCategory(): string { return $this->category; }
    public function setCategory(string $category): self { $this->category = $category; return $this; }
    public function isPublished(): bool { return $this->isPublished; }
    public function setIsPublished(bool $isPublished): self { $this->isPublished = $isPublished; return $this; }
    public function getViewCount(): int { return $this->viewCount; }
    public function setViewCount(int $viewCount): self { $this->viewCount = $viewCount; return $this; }
    public function getPublishedAt(): ?\DateTime { return $this->publishedAt; }
    public function setPublishedAt(?\DateTime $publishedAt): self { $this->publishedAt = $publishedAt; return $this; }
}
```

### Using the Document Manager

Inject the `DocumentManager` into your services:

```php
<?php
namespace App\Service;

use App\Document\Article;
use Phillarmonic\AllegroRedisOdmBundle\DocumentManager;

class ArticleService
{
    public function __construct(
        private DocumentManager $documentManager
    ) {
    }

    public function createArticle(string $title, string $slug, string $category): Article
    {
        $article = new Article();
        $article->setTitle($title);
        $article->setSlug($slug);
        $article->setCategory($category);
        $article->setPublishedAt(new \DateTime());
        $article->setIsPublished(true);

        $this->documentManager->persist($article);
        $this->documentManager->flush(); // Writes changes to Redis

        return $article;
    }

    public function findArticleById(string $id): ?Article
    {
        // The type hint for the return value should be ?Article
        return $this->documentManager->find(Article::class, $id);
    }

    public function findArticleBySlug(string $slug): ?Article
    {
        $repository = $this->documentManager->getRepository(Article::class);
        // The type hint for the return value should be ?Article
        return $repository->findOneBy(['slug' => $slug]);
    }
}
```

### Mapping Attributes

| Attribute         | Target   | Description                                                                 |
| ----------------- | -------- | --------------------------------------------------------------------------- |
| `#[Document]`     | Class    | Marks a class as a Redis document. Defines collection name and optional key prefix. |
| `#[RedisHash]`    | Class    | Stores document as a Redis hash (default if no storage type specified).     |
| `#[RedisJson]`    | Class    | Stores document as JSON in Redis (requires RedisJSON module).               |
| `#[Expiration]`   | Class    | Sets a default TTL (Time-To-Live) for all documents of this class.          |
| `#[Id]`           | Property | Marks a property as the document ID. Strategy can be `auto`, `manual`, or `none`. |
| `#[Field]`        | Property | Maps a property to a Redis field. Defines name, type, and nullability.      |
| `#[Index]`        | Property | Creates a secondary index (Redis SET) for a field, enabling fast lookups by value. Can have a `ttl`. |
| `#[SortedIndex]`  | Property | Creates a sorted index (Redis ZSET) for numeric or string fields, enabling efficient range queries. Can have a `ttl`. |

### Field Types

The `#[Field]` attribute supports the following types for data conversion:

-   `string` (default)
-   `integer`
-   `float`
-   `boolean`
-   `datetime` (stored as UNIX timestamp)
-   `json` (PHP array serialized as JSON string, useful for embedding simple structures)

### Command Line Tools

The bundle provides several console commands:

```bash
# Debug document mappings to troubleshoot configuration and class discovery
php bin/console allegro:debug-mappings

# Rebuild all indexes (useful after schema changes or if indexes become inconsistent)
php bin/console allegro:rebuild-indexes

# Remove stale/orphaned index entries from Redis
php bin/console allegro:purge-indexes

# Analyze performance characteristics of your document collections
php bin/console allegro:analyze-performance
```
Use the `--help` flag with any command for more options (e.g., `php bin/console allegro:rebuild-indexes --help`).

### Working with Repositories

The bundle provides a `DocumentRepository` class with finder methods:

```php
<?php
// Example controller or service
namespace App\Controller;

use App\Document\Article;
use Phillarmonic\AllegroRedisOdmBundle\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController; // Assuming Symfony context
use Symfony\Component\HttpFoundation\Response;

class ArticleController extends AbstractController
{
    public function __construct(
        private DocumentManager $documentManager
    ) {
    }

    public function listArticles(): Response
    {
        $articleRepository = $this->documentManager->getRepository(Article::class);

        // Find a single document by ID
        $article = $articleRepository->find('article123');

        // Find all documents. Uses SCAN for efficiency on large collections.
        $allArticlesResult = $articleRepository->findAll();
        $allArticles = $allArticlesResult->getResults();
        $totalArticleCount = $allArticlesResult->getTotalCount();


        // Find by criteria. Optimized to use Redis server-side operations (e.g., SINTERSTORE)
        // when multiple indexed fields are part of the criteria.
        $publishedTechArticlesResult = $articleRepository->findBy([
            'isPublished' => true,
            'category' => 'technology'
        ]);
        $publishedTechArticles = $publishedTechArticlesResult->getResults();

        // Find with ordering, limit and offset
        $recentArticlesResult = $articleRepository->findBy(
            ['isPublished' => true],
            ['publishedAt' => 'DESC'],  // Order by publishedAt descending
            10,                         // Limit to 10 results
            0                           // Start from offset 0
        );
        $recentArticles = $recentArticlesResult->getResults();

        // Find a single document by criteria
        $specificArticle = $articleRepository->findOneBy(['slug' => 'introduction-to-redis']);

        // Count documents. Uses SCAN for efficiency on large collections.
        $totalCount = $articleRepository->count();

        // ... controller logic
        return new Response('Found ' . count($recentArticles) . ' recent articles.');
    }
}
```

## Custom Repositories

Create custom repository classes for more specific query logic:

```php
<?php
// src/Repository/ArticleRepository.php (adjust namespace if needed)
namespace App\Repository; // Example namespace

use App\Document\Article; // Your document class
use Phillarmonic\AllegroRedisOdmBundle\Repository\DocumentRepository;
use Phillarmonic\AllegroRedisOdmBundle\Query\RangeQuery; // For range queries

class ArticleRepository extends DocumentRepository
{
    /**
     * Finds articles published within a certain number of days.
     * This example demonstrates a range query on a sorted index.
     */
    public function findRecentArticles(int $days = 30): array
    {
        // Assuming 'publishedAt' is a \DateTime field and has a #[SortedIndex]
        // and is stored as a timestamp.
        $startDateTimestamp = (new \DateTime("-{$days} days"))->getTimestamp();
        $endDateTimestamp = (new \DateTime())->getTimestamp();

        $rangeQuery = RangeQuery::create('publishedAt') // Property name with SortedIndex
            ->min($startDateTimestamp)
            ->max($endDateTimestamp)
            ->orderBy('publishedAt', 'DESC'); // Optional: order within the range

        $paginatedResult = $rangeQuery->execute($this);
        return $paginatedResult->getResults();
    }

    /**
     * Finds articles by a title pattern using memory-efficient streaming.
     */
    public function findByTitlePattern(string $pattern): array
    {
        $matchingArticles = [];
        // Use the stream method for memory efficiency with large datasets
        $this->stream(function (Article $article) use ($pattern, &$matchingArticles) {
            // Case-insensitive search
            if (stripos($article->getTitle(), $pattern) !== false) {
                $matchingArticles[] = $article;
            }
        });
        return $matchingArticles;
        // Note: For very complex pattern matching not suitable for direct Redis queries,
        // streaming and filtering in PHP is a viable approach.
        // If Redis Stack with RediSearch is available, consider its capabilities for full-text search.
    }
}
```

Then, reference your custom repository in the `#[Document]` attribute:

```php
<?php
// src/Document/Article.php
namespace App\Document;

use App\Repository\ArticleRepository; // Your custom repository
use Phillarmonic\AllegroRedisOdmBundle\Mapping\Document;
// ... other use statements

#[Document(collection: 'articles', prefix:'blog', repository: ArticleRepository::class)]
class Article
{
    // ...
}
```

## Configuration Reference

### Full Configuration

```yaml
allegro_redis_odm:
    # Redis client implementation (required)
    client_type: phpredis     # Options: phpredis, predis

    # Redis connection settings (required)
    connection:
        scheme: redis         # Options: redis, rediss (for TLS/SSL)
        host: 127.0.0.1
        port: 6379
        database: 0
        auth: null            # Optional password
        read_timeout: 0       # Read timeout in seconds (0 for no timeout).
        persistent: false     # Use persistent connections (phpredis only).
        options: {}           # Additional client-specific options.
                              # e.g., for Predis SSL: { ssl: { verify_peer: true, cafile: '/path/to/ca.pem' } }
                              # e.g., for phpredis SSL: { stream: { verify_peer: true, cafile: '/path/to/ca.pem' } } (handled by scheme: rediss)

    # Default storage settings (optional)
    default_storage:
        type: hash            # Options: hash, json. Default storage type if not specified on document.
        ttl: 0                # Default TTL in seconds for documents (0 = no expiration). Overridden by #[Expiration] on document.

    # Document mappings (required - at least one mapping)
    mappings:
        app:                  # Mapping name (arbitrary, e.g., 'main_documents', 'user_data')
            type: attribute   # Currently only 'attribute' mapping is supported.
            dir: '%kernel.project_dir%/src/Document' # Directory containing document classes for this mapping.
            namespace: 'App\Document'                # Base namespace for document classes in this directory.
            prefix: ''        # Optional prefix for all Redis keys generated by documents in this mapping.
                              # Can be overridden by #[Document(prefix: '...')] on the class.
```

## Advanced Usage

### Transactions

The `DocumentManager::flush()` operation groups all `persist()` and `remove()` calls made since the last flush into a single Redis transaction (MULTI/EXEC block) for atomicity.

```php
$article1 = new Article(); /* ... set properties ... */
$article2 = new Article(); /* ... set properties ... */

$documentManager->persist($article1);
$documentManager->persist($article2);
$documentManager->flush(); // article1 and article2 are saved in one transaction
```

### Large Dataset Operations

For handling very large datasets efficiently:

-   **BatchProcessor Service:**
    Inject `Phillarmonic\AllegroRedisOdmBundle\Service\BatchProcessor`. Use it to process large arrays of items or query results in manageable batches, helping to control memory usage during data imports, exports, or mass updates.
    ```php
    // Example: Importing data
    $itemsToImport = [/* ... large array of data ... */];
    $this->batchProcessor->processItems(
        $itemsToImport,
        function($itemData) {
            $article = Article::fromArray($itemData); // Assuming Article has a suitable factory
            // No need to call persist here, BatchProcessor handles it
            return $article;
        },
        100 // Batch size
    );
    ```

-   **BulkOperations Service:**
    Inject `Phillarmonic\AllegroRedisOdmBundle\Service\BulkOperations`. This service provides optimized methods like `bulkDelete()`, `bulkUpdate()`, `renameCollection()`, and `getCollectionStats()`. These are designed for efficiency with large datasets, often utilizing Redis `SCAN` and batching techniques internally.

-   **Streaming Results:**
    The `DocumentRepository::stream()` method allows you to process all documents matching criteria one by one (or in small internal batches) using a callback, which is highly memory-efficient for large collections.

### Sorted Indexes and Range Queries

For fields where you need to perform range-based lookups (e.g., timestamps, prices, scores, or even alphabetical ranges on strings), use `#[SortedIndex]`. This creates a Redis Sorted Set.

```php
// In your document class
#[Field(type: 'integer')]
#[SortedIndex] // Creates a sorted index on viewCount
private int $viewCount = 0;

#[Field(type: 'datetime')]
#[SortedIndex(name: 'published_time_idx')] // Custom index name
private ?\DateTime $publishedAt = null;
```

Query these using the `RangeQuery` builder:

```php
<?php
use Phillarmonic\AllegroRedisOdmBundle\Query\RangeQuery;
use App\Document\Article; // Your document

// ... in your service or controller
$repository = $this->documentManager->getRepository(Article::class);

// Find articles with viewCount between 100 and 1000
$queryByViews = RangeQuery::create('viewCount') // Property name with SortedIndex
                       ->min(100)
                       ->max(1000);
$articlesWithViews = $queryByViews->execute($repository)->getResults();

// Find articles published in the last 7 days (assuming publishedAt is stored as timestamp)
$sevenDaysAgoTimestamp = (new \DateTime('-7 days'))->getTimestamp();
$nowTimestamp = (new \DateTime())->getTimestamp();

$queryByDate = RangeQuery::create('publishedAt')
                       ->min($sevenDaysAgoTimestamp)
                       ->max($nowTimestamp)
                       ->orderBy('publishedAt', 'DESC') // Optional ordering
                       ->setMaxResults(20)             // Optional pagination
                       ->setFirstResult(0);
$recentArticles = $queryByDate->execute($repository)->getResults();
```
The `RangeQuery` builder translates these to efficient Redis sorted set commands.

### Using With TLS/SSL

For secure Redis connections:

```yaml
allegro_redis_odm:
    client_type: phpredis # or predis
    connection:
        scheme: rediss  # Note the double 's' for SSL
        host: my-secure-redis-server.com
        port: 6380      # Or your SSL port
        auth: 'my-password'
        # For Predis, you might need to add specific SSL options under 'options':
        # options:
        #   ssl:
        #     verify_peer: true
        #     verify_peer_name: true
        #     cafile: '/path/to/your/ca.pem'
```
The bundle attempts to configure basic TLS options for `phpredis` when `scheme: rediss` is used. For more advanced SSL configurations with `predis`, use the `connection.options.ssl` array.

### Working with Redis JSON

To use the JSON storage format (requires RedisJSON module on your Redis server):

```php
<?php
// src/Document/Product.php
namespace App\Document;

use Phillarmonic\AllegroRedisOdmBundle\Mapping\Document;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\RedisJson;
// ... other attributes

#[Document(collection: 'products')]
#[RedisJson] // Store this document type as JSON
class Product
{
    #[Id]
    private ?string $id = null;

    #[Field]
    private string $name;

    #[Field(type: 'json')] // This field itself will be a JSON structure within the main JSON doc
    private array $features = [];

    // ... Getters and setters
}
```

### Custom ID generation

By default, IDs are auto-generated using `uniqid()` if the ID property is null when `persist()` is called. You can control this:

```php
#[Id(strategy: 'manual')] // Your application is responsible for setting the ID before persist.
private string $id;        // If ID is null, persist will throw an error.

// #[Id(strategy: 'auto')] // Default behavior.
// #[Id(strategy: 'none')] // No ID field managed by ODM (less common for top-level docs).
```

### Using Time-To-Live (TTL) on Indexes

You can set TTL on specific `#[Index]` or `#[SortedIndex]` entries to have them auto-expire from Redis. This is useful for temporary or frequently changing indexes.

```php
#[Field(type: 'boolean')]
#[Index(name: 'featured_articles', ttl: 86400)] // Index entries expire after 24 hours
private bool $isFeatured;

#[Field(type: 'integer')]
#[SortedIndex(name: 'trending_score', ttl: 3600)] // Sorted index entries expire after 1 hour
private int $trendingScore;
```
The TTL is applied to the Redis key representing the index value (for `Index`) or the sorted set key itself (for `SortedIndex`).

## Best Practices

1.  **Index Wisely:** Only create `#[Index]` or `#[SortedIndex]` on fields you frequently search or sort/range query by. Too many indexes can slow down writes and consume more memory.
2.  **Appropriate TTLs:** Use `#[Expiration]` on documents and `ttl` on indexes for data that can naturally expire. This helps manage Redis memory.
3.  **Batch Operations:** Utilize `DocumentManager::flush()` for multiple persists/removes, and the `BatchProcessor` or `BulkOperations` services for very large scale data manipulation.
4.  **Document Size:** While Redis can handle large values, aim for reasonably sized documents. If a part of your document is very large, frequently updated independently, or rarely accessed with the main document, consider if it should be a separate, linked document.
5.  **Understand SCAN vs KEYS:** This bundle uses Redis `SCAN` internally for operations like `findAll()` and `count()` to avoid blocking your Redis server with large key spaces. If writing custom low-level Redis interactions, prefer `RedisClientAdapter::scan()` over `keys()`.
6.  **Schema Evolution:** Adding new nullable fields is generally safe. For more complex changes (renaming, type changes), plan data migrations. The bundle itself doesn't provide automated migration tools; these would typically be custom scripts (e.g., Symfony commands).
7.  **Use Debug Commands:** `allegro:debug-mappings` is invaluable for diagnosing issues with your document definitions and configuration. `allegro:analyze-performance` can provide insights into your data characteristics.

## License

This bundle is released under the MIT License. See the bundled LICENSE file for details.

## Credits

Developed by the Phillarmonic Team.

For questions, issues, or contributions, please visit the [GitHub repository](https://github.com/phillarmonic/allegro-redis-odm-bundle).