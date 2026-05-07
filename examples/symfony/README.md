# Symfony Example

This example shows the DebugBundle PHP SDK running through the Symfony event-subscriber path.

## Run

```bash
cd sdks/debugbundle-php
DEBUGBUNDLE_TOKEN=dbundle_proj_example \
DEBUGBUNDLE_ENDPOINT=http://127.0.0.1:8080 \
php -S 127.0.0.1:9002 -t examples/symfony/public
```

## Routes

- `/` returns a healthy JSON response.
- `/log` emits a `log_event` and a `request_event`.
- `/exception` triggers a `backend_exception` and a `request_event`.

Point `DEBUGBUNDLE_ENDPOINT` at a local ingest stub or the DebugBundle API to watch the emitted events.