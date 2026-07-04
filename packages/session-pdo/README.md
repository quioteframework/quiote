# quioteframework/session-pdo

PDO-backed session storage for [Quiote](https://github.com/quioteframework/quiote). Ships two independent implementations against Quiote's two session mechanisms:

- **`Quiote\Session\Pdo\PdoSessionPersistence`** implements `Quiote\Session\SessionPersistenceInterface` (`load`/`save`/`delete`), the backend for `Quiote\Session\SessionManager` — the modern, PSR-7-based session mechanism, safe under persistent worker runtimes (FrankenPHP, RoadRunner). **Prefer this for new code.**
- **`Quiote\Storage\Pdo\PdoSessionStorage`** implements PHP's native `SessionHandlerInterface` for the legacy `Storage`/`SessionStorage` mechanism (`$_SESSION`/`session_start()`). Kept for apps already built on that mechanism.

## Install

```
composer require quioteframework/session-pdo
```

Both expect a table shaped like:

```sql
CREATE TABLE session (
    sess_id   VARCHAR(64) PRIMARY KEY,
    sess_data BYTEA/BLOB/TEXT NOT NULL,
    sess_time TIMESTAMP NOT NULL
);
```

## `PdoSessionPersistence`

```php
$manager = new \Quiote\Session\SessionManager(
    new \Quiote\Session\Pdo\PdoSessionPersistence($pdo, table: 'session'),
);
```

## `PdoSessionStorage`

Configure via `storage.xml`/factories with the required `db_table` parameter (optional: `database`, `db_id_col`, `db_data_col`, `db_time_col`, `data_as_lob`, `date_format`) — see the class docblock for the full list.

## License

MIT. See [LICENSE](LICENSE).
