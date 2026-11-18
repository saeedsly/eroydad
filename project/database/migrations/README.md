# Database baseline and migrations

`../schema.sql` is migration `001_initial_schema` plus its initial role seed. It is for a **new empty database only**. It creates the migration ledger row `001_initial_schema`; it does not upgrade an existing installation. There is no in-place migration runner or backwards migration in this release.

For any future schema change, add a new numbered SQL migration, review its forward and rollback plan against MariaDB 10.4, back up and rehearse on a copy first, then apply exactly once during a maintenance window and record the version in `schema_migrations`. Never edit/re-import the baseline on a database with real data. No migration or seed process may include a default admin password or real employee data.
