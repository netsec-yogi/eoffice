-- Separate database for the PHPUnit suite (phpunit.xml), so RefreshDatabase
-- never touches development data.
CREATE DATABASE digital_workflow_testing OWNER workflow;
