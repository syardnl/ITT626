# Keep a single seeded Property row

The system manages exactly one development, so the source ERD's `ORGANIZATION` could live in configuration. We keep it, renamed **Property**, as a single seeded row that Users and Locations reference, so the schema stays traceable to the case study ERD. This is not groundwork for multi-property support, which is out of scope.
