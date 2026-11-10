# Explicit cache/session profiles for synthetic release checks

The compatibility profile selects Redis for both application cache and limiter, with database sessions. The target profile selects database for application cache, limiter and sessions, matching the verified deployment configuration. Redis 7.0.15 is installed in the target environment but is not its active application cache or limiter; its presence is not evidence that production uses it.

profiles.json declares cache_store, cache_limiter, session_driver and redis_role. Both orchestrators reject cache_store values outside redis/database and reject inconsistent limiter/session declarations. RELEASE_CACHE_STORE is passed explicitly to the backend container, host-browser server container and every HTTP child environment. Missing or invalid values fail before a PHP process or database fixture is started. No inherited CACHE_STORE or CACHE_LIMITER overrides the selected profile.

HTTP/browser evidence includes http-effective-configuration-proof.json. The probe loads Laravel environment/configuration only, before database creation, without booting providers or connecting to the database. It records only cache.default, cache.limiter and session.driver, compares them with the selected values and fails on mismatch. The suite's separate full Laravel bootstrap proof remains authoritative for its executed test environment. SQLite-only and upgrade-fixture array-cache isolation are separate harness scopes, not claims of target HTTP configuration.

No lockfile, production setting, credential, Redis server configuration or security policy is changed by this selection. Real HTTP/browser reruns remain necessary after changing the profile; syntax and mocked propagation tests alone do not establish those results.
