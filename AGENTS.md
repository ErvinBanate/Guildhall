# Project conventions

Implement the requested phase only. This project starts at Phase 1.

- Never introduce databases, ORMs, migrations, persistent server user records, or attendance storage without explicit authorization.
- Use Laravel routes and controllers, MySQL for application storage, and strict TypeScript Inertia React pages under resources/js.
- Production statistics must come from verified Albion Gameinfo responses. Validate response contracts; missing values are unavailable, not zero. Never ship mock statistics or mock authentication in production.
- Use Albion player IDs for attendance, deduplicate, and label unmatched members Unconfirmed. Disclose use of the current roster rather than a historical snapshot. Add attendance calculation tests in Phase 6.
- Local character selection does not verify ownership. Manual attendance adjustments remain local and do not sync across devices.
- Protect every permission-bearing backend endpoint and fail closed until authentication exists. Frontend guards are not API authorization.
- Keep secrets out of Vite variables and version control. Refresh Discord roles periodically and for sensitive operations.
- Serve the app from one origin. Use HttpOnly Secure session cookies in production, CSRF and origin checks on browser mutations, signature checks on Discord interactions, and no-store for authentication responses.
- Run npm run check and php artisan test before delivery. Test meaningful behavior and security boundaries.
- Update README.md for implementation status, setup, and deployment changes.
