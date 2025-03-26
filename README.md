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
// src/Document/User.php
namespace App\Document;

use Phillarmonic\AllegroRedisOdmBundle\Mapping\Document;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\Field;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\Id;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\Index;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\RedisHash;
use Phillarmonic\AllegroRedisOdmBundle\Mapping\Expiration;

#[Document(collection: 'users')]
#[RedisHash]
#[Expiration(ttl: 3600)] // Optional: 1-hour expiration
class User
{
    #[Id]
    private ?string $id = null;
    
    #[Field]
    #[Index]
    private string $email;
    
    #[Field(name: 'username', nullable: false)]
    private string $username;
    
    #[Field(type: 'integer')]
    private int $loginCount = 0;
    
    #[Field(type: 'datetime')]
    private ?\DateTime $lastLogin = null;
    
    // Getters and setters...
    
    public function getId(): ?string
    {
        return $this->id;
    }
    
    public function getEmail(): string
    {
        return $this->email;
    }
    
    public function setEmail(string $email): self
    {
        $this->email = $email;
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

use App\Document\User;
use Phillarmonic\AllegroRedisOdmBundle\DocumentManager;

class UserService
{
    public function __construct(
        private DocumentManager $documentManager
    ) {
    }
    
    public function createUser(string $email, string $username): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setUsername($username);
        $user->setLastLogin(new \DateTime());
        
        $this->documentManager->persist($user);
        $this->documentManager->flush();
        
        return $user;
    }
    
    public function findUserById(string $id): ?User
    {
        return $this->documentManager->find(User::class, $id);
    }
    
    public function findUserByEmail(string $email): ?User
    {
        $repository = $this->documentManager->getRepository(User::class);
        return $repository->findOneBy(['email' => $email]);
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

## Custom Repositories

You can create custom repository classes for more specific queries:

```php
<?php
// src/Document/Repository/UserRepository.php
namespace App\Document\Repository;

use App\Document\User;
use Phillarmonic\AllegroRedisOdmBundle\Repository\DocumentRepository;

class UserRepository extends DocumentRepository
{
    public function findActiveUsers(): array
    {
        $allUsers = $this->findAll();
        $activeUsers = [];
        
        foreach ($allUsers as $user) {
            if ($user->getLastLogin() && $user->getLastLogin() > new \DateTime('-30 days')) {
                $activeUsers[] = $user;
            }
        }
        
        return $activeUsers;
    }
}
```

Then reference it in your document:

```php
#[Document(collection: 'users', repository: UserRepository::class)]
class User
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
#[Document(collection: 'products')]
#[RedisJson]
class Product
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
#[Index(name: 'session_token', ttl: 3600)] // 1-hour TTL on this index
private string $sessionToken;
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