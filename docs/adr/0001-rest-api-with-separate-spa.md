# Laravel REST API with a separate React SPA

The backend is a Laravel JSON REST API under `/api/v1`, consumed by a separate React + TypeScript SPA served from the same origin with Sanctum session cookies. Laravel + Inertia would have removed most of the API layer and been quicker to build, but the API design is a core deliverable of a back-end technology course, so we kept an explicit, versioned API contract.
