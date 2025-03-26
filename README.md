# AllegroRedisOdmBundle

A Symfony bundle providing an Object Document Mapper (ODM) for Redis. This bundle simplifies storing, retrieving, and managing PHP objects in Redis with support for various Redis storage formats, indexing, and automated object hydration.

## Features

- **Simple object persistence** - Store PHP objects directly in Redis
- **Multiple storage formats** - Store documents as Redis Hashes or JSON
- **Automatic indexing** - Create and maintain secondary indices for fast lookups
- **Repository pattern** - Clean data access through document repositories
- **Attribute-based mapping** - Define document structure using PHP 8 attributes
- **TTL support** - Set expiration times for documents and indices
- **Multiple client support** - Works with both PhpRedis and Predis clients
- **Symfony integration** - Seamlessly integrates with Symfony framework

## Requirements

- PHP 8.2 or higher
- Symfony 6.0+ or 7.0+
- Redis server
- Either the PHP Redis extension (`ext-redis`) or `predis/predis` package

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
        # read_timeout: 0
        # persistent: false
    
    # Default storage settings
    default_storage:
        type: hash         # Options: hash, json
        ttl: 0             # Default TTL in seconds (0 = no expiration)
    
    # Document mappings
    mappings:
        app:
            dir: '%kernel.project_dir%/src/Document'
            namespace: 'App\Document'
            prefix: 'app'  # Optional prefix for all Redis keys
```

## Usage

### Defining Documents

Create document classes in your project:

```php
<?php
// src/Document/Article.php
namespace App\Document;

use Phillarmonic\AllegroRedisOdmBundle\Mapping\Document;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\Field;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\Id;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\Index;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\RedisHash;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\Expiration;

#[Document(collection: 'articles')]
#[RedisHash]
#[Expiration(ttl: 3600)] // Optional: 1-hour expiration
class Article
{
    #[Id]
    private ?string $id = null;
    
    #[Field]
    #[Index]
    private string $slug;
    
    #[Field(name: 'title', nullable: false)]
    private string $title;
    
    #[Field(type: 'string', nullable: true)]
    private ?string $content = null;
    
    #[Field]
    #[Index]
    private string $category;
    
    #[Field(type: 'boolean')]
    private bool $isPublished = false;
    
    #[Field(type: 'integer')]
    private int $viewCount = 0;
    
    #[Field(type: 'datetime')]
    private ?\DateTime $publishedAt = null;
    
    // Getters and setters...
    
    public function getId(): ?string
    {
        return $this->id;
    }
    
    public function getSlug(): string
    {
        return $this->slug;
    }
    
    public function setSlug(string $slug): self
    {
        $this->slug = $slug;
        return $this;
    }
    
    public function getTitle(): string
    {
        return $this->title;
    }
    
    public function setTitle(string $title): self
    {
        $this->title = $title;
        return $this;
    }
    
    // Add other getters and setters...
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
        
        $this->documentManager->persist($article);
        $this->documentManager->flush();
        
        return $article;
    }
    
    public function findArticleById(string $id): ?Article
    {
        return $this->documentManager->find(Article::class, $id);
    }
    
    public function findArticleBySlug(string $slug): ?Article
    {
        $repository = $this->documentManager->getRepository(Article::class);
        return $repository->findOneBy(['slug' => $slug]);
    }
}
```

### Mapping Attributes

| Attribute | Target | Description |
|-----------|--------|-------------|
| `#[Document]` | Class | Marks a class as a Redis document |
| `#[RedisHash]` | Class | Stores document as a Redis hash (default) |
| `#[RedisJson]` | Class | Stores document as JSON in Redis |
| `#[Expiration]` | Class | Sets TTL for document |
| `#[Id]` | Property | Marks a property as the document ID |
| `#[Field]` | Property | Maps a property to a Redis field |
| `#[Index]` | Property | Creates a secondary index for a field |

### Field Types

The `Field` attribute supports the following types:
- `string` (default)
- `integer`
- `float`
- `boolean`
- `datetime` (stored as UNIX timestamp)
- `json` (serialized as JSON string)

### Command Line Tools

The bundle provides several console commands to help with management:

```bash
# Debug document mappings to troubleshoot mapping issues
php bin/console allegro:debug-mappings

# Rebuild all indexes (useful after schema changes)
php bin/console allegro:rebuild-indexes

# Remove stale/orphaned indexes
php bin/console allegro:purge-indexes
```

### Working with Repositories

The bundle provides a `DocumentRepository` class with several finder methods similar to Doctrine:

```php
<?php
// Example controller or service using repositories
namespace App\Controller;

use App\Document\Article;
use App\Document\Product;
use Phillarmonic\AllegroRedisOdmBundle\DocumentManager;
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
        
        // Find all documents
        $allArticles = $articleRepository->findAll();
        
        // Find by criteria - uses indexed fields when available
        $publishedTechArticles = $articleRepository->findBy([
            'isPublished' => true,
            'category' => 'technology'
        ]);
        
        // Find with ordering, limit and offset
        $recentArticles = $articleRepository->findBy(
            ['isPublished' => true],
            ['publishedAt' => 'DESC'],  // Order by publishedAt descending
            10,                         // Limit to 10 results
            0                           // Start from offset 0
        );
        
        // Find a single document by criteria
        $article = $articleRepository->findOneBy(['slug' => 'introduction-to-redis']);
        
        // Count documents
        $totalCount = $articleRepository->count();
        
        // ... controller logic
    }
}
```

## Custom Repositories

You can create custom repository classes for more specific queries:

```php
<?php
// src/Document/Repository/ArticleRepository.php
namespace App\Document\Repository;

use App\Document\Article;
use Phillarmonic\AllegroRedisOdmBundle\Repository\DocumentRepository;

class ArticleRepository extends DocumentRepository
{
    public function findRecentArticles(int $days = 30): array
    {
        $allArticles = $this->findAll();
        $recentArticles = [];
        
        foreach ($allArticles as $article) {
            if ($article->getPublishedAt() && $article->getPublishedAt() > new \DateTime('-' . $days . ' days')) {
                $recentArticles[] = $article;
            }
        }
        
        return $recentArticles;
    }
    
    public function findByTitlePattern(string $pattern): array
    {
        // Using lower-level Redis client via document manager
        $redisClient = $this->documentManager->getRedisClient();
        
        // Get all document keys for this collection
        $keyPattern = $this->metadata->getCollectionKeyPattern();
        $keys = $redisClient->keys($keyPattern);
        
        $result = [];
        foreach ($keys as $key) {
            // Extract ID from key
            $parts = explode(':', $key);
            $id = end($parts);
            
            // Find the document
            $article = $this->find($id);
            
            // Apply custom filtering
            if ($article && str_contains(strtolower($article->getTitle()), strtolower($pattern))) {
                $result[] = $article;
            }
        }
        
        return $result;
    }
}
```

Then reference it in your document:

```php
#[Document(collection: 'articles', repository: ArticleRepository::class)]
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
        read_timeout: 0       # Read timeout in seconds
        persistent: false     # Use persistent connections
        options: []           # Additional client-specific options
    
    # Default storage settings (optional)
    default_storage:
        type: hash            # Options: hash, json
        ttl: 0                # Default TTL in seconds (0 = no expiration)
    
    # Document mappings (required - at least one mapping)
    mappings:
        app:                  # Mapping name (arbitrary)
            type: attribute   # Currently only attribute mapping is supported
            dir: '%kernel.project_dir%/src/Document' # Directory containing document classes
            namespace: 'App\Document'                # Namespace for document classes
            prefix: ''        # Optional prefix for Redis keys
```

## Advanced Usage

### Transactions and Batch Processing

Use the document manager to persist multiple objects in a single transaction:

```php
// Persist multiple entities at once
$article1 = new Article();
$article1->setTitle('Introduction to Redis');
$article1->setContent('Redis is an in-memory data structure store...');
$article1->setCategory('databases');
$article1->setPublishedAt(new \DateTime());
$article1->setIsPublished(true);
$article1->setSlug('introduction-to-redis');

$article2 = new Article();
$article2->setTitle('Redis vs. MongoDB');
$article2->setContent('When comparing Redis and MongoDB...');
$article2->setCategory('databases');
$article2->setPublishedAt(new \DateTime());
$article2->setIsPublished(true);
$article2->setSlug('redis-vs-mongodb');

// Both will be persisted in a single Redis transaction
$documentManager->persist($article1);
$documentManager->persist($article2);
$documentManager->flush();
```

### Indexing and Querying

The ODM uses Redis Sets for indexing. When you mark a property with `#[Index]`, a Redis Set is created that maps each unique value of the property to all document IDs that have that value.

```php
// In your document class
#[Field]
#[Index]
private string $category;

// Then, when querying:
$articles = $repository->findBy(['category' => 'technology']);
```

This query will be highly efficient because it uses Redis sets to directly find the relevant document IDs without scanning all documents.

### Using With TLS/SSL

For secure Redis connections:

```yaml
allegro_redis_odm:
    client_type: phpredis
    connection:
        scheme: rediss  # Note the double 's' for SSL
        host: my-redis-server.com
        port: 6380
        auth: 'my-password'
```

### Working with Redis JSON

To use the JSON storage format:

```php
#[Document(collection: 'articles')]
#[RedisJson]
class Article
{
    // Document properties...
}
```

Remember that Redis must have the RedisJSON module installed for this to work.

### Custom ID generation

By default, IDs are auto-generated using `uniqid()`. You can customize the ID strategy:

```php
#[Id(strategy: 'manual')] // Options: 'auto', 'manual', 'none'
private string $id;
```

### Using Time-To-Live (TTL) on indexes

You can set TTL on specific indexes to auto-expire them:

```php
#[Field]
#[Index(name: 'featured', ttl: 86400)] // 24-hour TTL on this index
private bool $isFeatured;
```

## Best Practices

1. **Use indexes wisely** - Only create indexes on fields you frequently search by
2. **Set appropriate TTLs** - Use TTL for temporary data to avoid Redis memory growth
3. **Batch operations** - Use bulk persistence when working with multiple documents
4. **Keep documents small** - Redis works best with relatively compact documents
5. **Use the debug command** - `allegro:debug-mappings` helps diagnose mapping issues

## License

This bundle is released under the MIT License. See the bundled LICENSE file for details.

## Credits

Developed by the Phillarmonic Team. 

For questions, issues, or contributions, please visit the [GitHub repository](https://github.com/phillarmonic/allegro-redis-odm-bundle).