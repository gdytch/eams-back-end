# API documentation

- `openapi.yaml` in this repository is the canonical API description for both repositories. `AMS-Frontend/openapi.yaml` is a copy: a frontend change replaces it whole with this file and never edits it by hand.
- Every change that adds, removes or changes an API route, request field or response shape updates `openapi.yaml` in the same change.
- Every route in `php artisan route:list --path=api` has a matching path and method in `openapi.yaml`, and every documented path has a route. Routes registered as `PUT|PATCH` are documented once, as `put`, with "Also accepts `PATCH`."
- Check the file with `npx @redocly/cli lint openapi.yaml` before finishing. It must report no errors.
