-- Runs once, when the pgsql volume is first created: the database phpunit uses
-- (tests/TestCase.php refuses anything that doesn't look like a test database).
SELECT 'CREATE DATABASE indlela_testing'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'indlela_testing')\gexec
