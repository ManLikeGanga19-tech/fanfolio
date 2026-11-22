-- citext for case-insensitive emails (spec: users.email citext). Installing it in template1
-- means every database created later has it, including parallel test copies (fanfolio_test_<n>).
\connect template1
CREATE EXTENSION IF NOT EXISTS citext;

\connect fanfolio
CREATE EXTENSION IF NOT EXISTS citext;

CREATE DATABASE fanfolio_test OWNER fanfolio;
