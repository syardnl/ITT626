# MariaDB 11.8 instead of MySQL 8

The design document proposed a MySQL 8-compatible database. We use MariaDB 11.8 because it is already installed in the development environment and Laravel supports it directly. Avoid MySQL-only features (e.g. some JSON functions and `CHECK` behaviour differences) so the schema stays portable to MySQL 8 if marking or hosting requires it.
