# Antigravity OS — Affentra Project Rules

> **Project**: Affentra
> **PHP**: ^8.2
> **Laravel**: ^12.0
> **Frontend**: Vue.js 3 + Inertia.js + Tailwind CSS
> **Last Updated**: 2026-03-31

---

## Tech Stack

| Layer | Technology | Notes |
|-------|------------|-------|
| PHP | 8.2+ | Property promotion, readonly classes |
| Laravel | 12.0 | Latest stable |
| Frontend | Vue.js 3 | Composition API |
| Routing | Inertia.js | SPA-like navigation |
| CSS | Tailwind CSS | Utility-first |
| Icons | Heroicons / Lucide | Consistent set |
| State | Pinia (if needed) | NOT global window.state |
| Build | Vite | Fast HMR |

---

## Architecture

```
Controller (HTTP only, THIN)
└── Service (Business Logic)
    └── Repository (DB Query)
        └── Model
```

### Repository Types

| Model | Base Class |
|-------|------------|
| Standard | `BaseRepository` hoặc `Prettus\Repository` |
| Translatable | `BaseTranslationRepository` (nếu có) |

---

## Frontend Guardrails

### Vue.js 3 + Inertia.js Pattern

```vue
<!-- Page Component -->
<template>
  <div>
    <h1>{{ title }}</h1>
    <DataTable :data="products" />
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
  products: Array,
  title: String
})

const isLoading = ref(false)

const handleAction = () => {
  isLoading.value = true
  router.post('/endpoint', data, {
    onFinish: () => isLoading.value = false
  })
}
</script>
```

### Tailwind CSS

- Utility-first approach
- Custom config in `tailwind.config.js`
- Components: Extract khi pattern lặp lại

### Inertia.js Conventions

```php
// Controller returns Inertia response
return inertia('Products/Index', [
    'products' => $products,
    'filters' => $filters
]);
```

---

## Package-Specific Features

### Inertia.js

```php
use Inertia\Inertia;

// Sharing data globally
Inertia::share('auth.user', fn () => auth()->user());
```

### Ziggy

```javascript
// Route helper trong Vue
route('products.show', { id: 1 })
```

### Prettus Repository (l5-repository)

```php
use Prettus\Repository\Eloquent\BaseRepository;

class ProductRepository extends BaseRepository
{
    public function model()
    {
        return Product::class;
    }
}
```

---

## MCP Tools Priority

```
1. jetbrains:get_project_modules
2. jetbrains:get_php_project_config → Verify PHP 8.2
3. laravel_boost:list_symfony_routes_controllers
4. jetbrains:search_symbol("Inertia")
5. jetbrains:search_symbol("BaseRepository")
```

---

## Code Style

- **Laravel Pint**: Configured (see `composer.json`)
- **PSR-12**: Strict compliance
- **Vue**: ESLint + Prettier (nếu có)
- **Reformat**: Use `jetbrains:reformat_file`

---

## Testing

```bash
# Run all tests
composer test

# With artisan
php artisan test
```

---

## API Response Contract

```json
{
  "ok": true|false,
  "data": { ... }|null,
  "message": null|"Human-readable error",
  "errors": null|{ "field": ["Validation error"] }
}
```

---

## Security

- Validation → `FormRequest`
- Authorization → `Policy/Gate`
- CSRF: Inertia tự động handle
- Ziggy route helper an toàn

---

## See Also

- Master rules: `~/.claude/phpstorm-rules.md`
