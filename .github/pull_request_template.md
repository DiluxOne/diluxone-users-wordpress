## 📝 What changes



## 💡 Why



## 🧪 How I tested it

- [ ] `make check` passes locally
- [ ] Integration on a network (`make env-multisite && make test-integration`), if it touches WordPress behaviour
- [ ] End-to-end on a single site (`make test-e2e`) and on a network (`make test-e2e-network`), if a person can see or do it
- [ ] Ways in exercised (e-mail link, password, social provider, passkey, second factor), if sign-in is involved

## 📸 Screenshots



## ✅ Checklist

- [ ] Tests added at every layer this touches, and `tests/e2e/COVERAGE.md` updated for a new feature or state (`docs/testing-and-quality.md`).
- [ ] If a screen changed: the visual baselines retaken on purpose (`make test-visual-update`) and, for a listing screen, `make screenshots`.
- [ ] User-facing strings are wrapped in WordPress translation functions with the `diluxone-users` text domain, and the eight locales are updated.
- [ ] User input is unslashed and sanitized; output is escaped; SQL is prepared.
- [ ] No codes, tokens, secrets or personal data are logged or stored in the clear.
- [ ] If this changes what a user sees, one bullet was added to the newest `= X.Y.Z =` entry of `readme.txt` (see `docs/release.md`).
- [ ] Docs that describe what this PR changes are updated in this PR (`readme.txt`, `docs/`, `AGENTS.md`, `docs/architecture.md`, `docs/extending.md`).
