<?php

declare(strict_types=1);

namespace Nova\Core;

use RuntimeException;

/**
 * Runtime configuration.
 *
 * - On a server: a PHP file returning an array, outside the document root
 *   (`~/config/config.php`, 05 §1). When that file exists it is the only
 *   source; environment variables are ignored, so a stray variable cannot
 *   switch a server into development mode.
 * - In the Docker dev stack: the NOVA_* variables set by compose.yaml.
 *
 * Secrets live only here, never in the repository (D23).
 */
final readonly class Config
{
    /** @param array<string, mixed> $values */
    public function __construct(
        public Environment $environment,
        public DatabaseConfig $database,
        public array $values = [],
    ) {}

    public static function load(string $configFile): self
    {
        if (is_file($configFile)) {
            $values = require $configFile;
            if (!\is_array($values)) {
                throw new RuntimeException('The configuration file must return an array.');
            }
            /** @var array<string, mixed> $values */
            return self::fromArray($values);
        }

        return self::fromEnvironment();
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $db = $values['database'] ?? null;
        if (!\is_array($db)) {
            throw new RuntimeException('Configuration is missing the "database" section.');
        }

        return new self(
            Environment::fromValue($values['environment'] ?? null),
            new DatabaseConfig(
                self::string($db, 'host'),
                is_numeric($db['port'] ?? null) ? (int) $db['port'] : 3306,
                self::string($db, 'name'),
                self::string($db, 'user'),
                self::string($db, 'password'),
            ),
            $values,
        );
    }

    public static function fromEnvironment(): self
    {
        $get = static function (string $name, ?string $default = null): string {
            $value = getenv($name);
            if (\is_string($value) && $value !== '') {
                return $value;
            }
            if ($default !== null) {
                return $default;
            }

            throw new RuntimeException("Configuration: {$name} is not set and no configuration file exists.");
        };

        return new self(
            Environment::fromValue(getenv('NOVA_ENV')),
            new DatabaseConfig($get('NOVA_DB_HOST'), (int) $get('NOVA_DB_PORT', '3306'), $get('NOVA_DB_NAME'), $get('NOVA_DB_USER'), $get('NOVA_DB_PASSWORD')),
            [
                'smtp' => ['host' => $get('NOVA_SMTP_HOST', ''), 'port' => (int) $get('NOVA_SMTP_PORT', '25')],
                'test_database' => $get('NOVA_DB_TEST_NAME', ''),
            ],
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    /** @param array<array-key, mixed> $section */
    private static function string(array $section, string $key): string
    {
        $value = $section[$key] ?? null;
        if (!\is_string($value)) {
            throw new RuntimeException("Configuration: database.{$key} must be a string.");
        }

        return $value;
    }
}
