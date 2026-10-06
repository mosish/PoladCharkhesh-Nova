-- Runs once, when the db-data volume is first created.
-- The image has already created the `nova` database and the `nova` user.

ALTER DATABASE nova CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;

-- Separate database for the integration tests, so they never touch dev data.
CREATE DATABASE IF NOT EXISTS nova_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;
GRANT ALL PRIVILEGES ON nova_test.* TO 'nova'@'%';
