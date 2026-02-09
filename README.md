# SemanticLinker AI
## Autor
SemanticLinker AI © 2024-2025
Wojciech Władziński


**Automatyczne linkowanie wewnętrzne oparte na semantyce dla WordPress**

Plugin wykorzystuje embeddingi AI (Google Gemini) do inteligentnego tworzenia linków wewnętrznych między powiązanymi tematycznie artykułami.

> **Uwaga:** Plugin obsługuje wyłącznie język polski.

---

## Kluczowe funkcje

### 1. Semantyczne dopasowywanie treści
- **Embeddingi wektorowe** – każdy artykuł jest reprezentowany jako wektor liczbowy (embedding) generowany przez Google Gemini API
- **Cosine similarity** – podobieństwo między artykułami mierzone jest za pomocą podobieństwa kosinusowego wektorów
- **Konfiguralny próg** – użytkownik określa minimalny próg podobieństwa (domyślnie 0.75)

### 2. Inteligentna ekstrakcja anchor text
- **N-gramy** – algorytm analizuje fragmenty tekstu (3-10 słów) szukając najlepszego anchor text
- **Filtrowanie interpunkcji** – anchor nie może zaczynać/kończyć się znakiem interpunkcyjnym
- **Stop words** – pomijane są anchory kończące się spójnikami i przyimkami
- **Scoring F1** – kombinacja precision i recall określa jakość dopasowania anchor-tytuł

### 3. Klastrowanie anchorów
- **Deduplikacja semantyczna** – podobne anchory (np. "kredyt hipoteczny" ≈ "kredytu hipotecznego") są grupowane
- **Próg klastrowania** – konfigurowalny próg podobieństwa dla grupowania (domyślnie 0.75)
- **Jeden anchor = jeden cel** – dany anchor zawsze linkuje do tego samego artykułu docelowego

### 4. Non-destructive link injection
- **Filtr `the_content`** – linki są wstrzykiwane w czasie renderowania strony
- **Brak modyfikacji bazy** – oryginalna treść artykułów pozostaje nienaruszona
- **Cache transient** – przetworzone HTML cachowane dla wydajności
- **Wykluczane tagi** – linki nie są wstawiane w nagłówkach, kodzie, skryptach

### 5. Opcjonalny filtr AI Gemini
- **Walidacja kontekstowa** – Gemini sprawdza czy anchor pasuje kontekstowo do tytułu docelowego
- **Redukcja false positives** – eliminuje linki, które przeszły próg podobieństwa, ale nie są sensowne

### 6. Panel administracyjny
- **Dashboard linków** – przegląd wszystkich aktywnych, odrzuconych i wyfiltrowanych linków
- **Widok klastrów** – linki grupowane według URL docelowego
- **Zarządzanie** – możliwość odrzucenia/przywrócenia pojedynczych linków
- **Blacklista** – trwałe wykluczenie par (artykuł źródłowy, URL docelowy)

### 7. Batch processing z progress tracking
- **Przetwarzanie wsadowe** – indeksowanie i matching w małych partiach (25 postów)
- **Pasek postępu** – wizualizacja postępu dla każdej fazy (indeksowanie → matching → filtrowanie AI)
- **Anulowanie** – możliwość przerwania procesu w dowolnym momencie
- **Wznawianie** – proces kontynuuje od miejsca przerwania

### 8. Bezpieczeństwo
- **Szyfrowanie API key** – klucz API szyfrowany AES-256-CBC (lub XOR fallback)
- **Rate limiting** – ochrona przed nadmiernym zużyciem API (30 req/min)
- **Nonce verification** – ochrona CSRF dla wszystkich operacji AJAX
- **Capability checks** – tylko administratorzy mają dostęp

---

## Użyte technologie

### Backend (PHP 7.4+)

| Technologia | Zastosowanie |
|-------------|--------------|
| **WordPress Plugin API** | Hooks, filters, transients, options, AJAX |
| **Google Gemini API** | Generowanie embeddingów tekstowych |
| **OpenSSL** | Szyfrowanie AES-256-CBC klucza API |
| **DOMDocument** | Parsowanie i modyfikacja HTML (link injection) |
| **libxml** | Obsługa błędów parsowania DOM |
| **wpdb** | Bezpieczne zapytania SQL z prepared statements |
| **WP-Cron** | Opcjonalne automatyczne indeksowanie co godzinę |

### Frontend (JavaScript/jQuery)

| Technologia | Zastosowanie |
|-------------|--------------|
| **jQuery** | Manipulacja DOM, obsługa AJAX |
| **localStorage** | Persist stanu checkboxów między sesjami |
| **CSS Flexbox/Grid** | Layout panelu administracyjnego |
| **CSS Gradients** | Flaga polska w pasku statusu |

### Baza danych

Plugin tworzy własne tabele:

```sql
-- Embeddingi wektorowe
wp_semantic_embeddings (
    post_id, chunk_idx, text_hash, embedding LONGBLOB
)

-- Wygenerowane linki
wp_semantic_links (
    post_id, anchor_text, target_url, target_post_id,
    similarity_score, status, created_at
)

-- Blacklista (trwałe wykluczenia)
wp_semantic_blacklist (
    post_id, anchor_text, target_url
)

-- Logi debugowania
wp_semantic_debug_logs (
    context, message, data JSON, created_at
)
```

### Algorytmy

| Algorytm | Opis |
|----------|------|
| **Cosine Similarity** | Miara podobieństwa wektorów embeddingów |
| **N-gram extraction** | Ekstrakcja kandydatów na anchor text |
| **F1 Score** | Kombinacja precision/recall dla scoringu anchorów |
| **Semantic clustering** | Grupowanie podobnych anchorów w klastry |

---

## Architektura

```
semanticlinker-ai/
├── semanticlinker-ai.php    # Główny plik pluginu (autoloader)
├── includes/
│   ├── class-sl-db.php          # Warstwa bazy danych
│   ├── class-sl-indexer.php     # Indeksowanie postów → embeddingi
│   ├── class-sl-matcher.php     # Dopasowywanie linków
│   ├── class-sl-injector.php    # Wstrzykiwanie linków w content
│   ├── class-sl-embedding-api.php # Klient Google Gemini API
│   ├── class-sl-settings.php    # Zarządzanie ustawieniami
│   ├── class-sl-dashboard.php   # Panel Active Links
│   ├── class-sl-ajax.php        # Endpointy AJAX
│   ├── class-sl-security.php    # Szyfrowanie, rate limiting
│   └── class-sl-debug.php       # System logowania
├── templates/
│   ├── settings.php             # Szablon strony ustawień
│   └── dashboard.php            # Szablon dashboardu linków
└── assets/
    ├── css/admin.css            # Style panelu admina
    └── js/admin.js              # Logika JS (AJAX, progress, UI)
```

---

## Flow przetwarzania

```
1. INDEKSOWANIE
   ┌─────────────┐     ┌──────────────┐     ┌─────────────┐
   │ get_posts() │ ──▶ │ chunk_text() │ ──▶ │ Gemini API  │
   └─────────────┘     └──────────────┘     │ embed()     │
                                            └──────┬──────┘
                                                   ▼
                                            ┌─────────────┐
                                            │ DB: save    │
                                            │ embeddings  │
                                            └─────────────┘

2. MATCHING
   ┌─────────────┐     ┌──────────────┐     ┌─────────────┐
   │ Load source │ ──▶ │ cosine()     │ ──▶ │ find_anchor │
   │ embeddings  │     │ vs targets   │     │ (n-grams)   │
   └─────────────┘     └──────────────┘     └──────┬──────┘
                                                   ▼
                                            ┌─────────────┐
                                            │ DB: insert  │
                                            │ links       │
                                            └─────────────┘

3. INJECTION (runtime)
   ┌─────────────┐     ┌──────────────┐     ┌─────────────┐
   │ the_content │ ──▶ │ DOMDocument  │ ──▶ │ inject <a>  │
   │ filter      │     │ parse HTML   │     │ elements    │
   └─────────────┘     └──────────────┘     └─────────────┘
```

---

## Wymagania

- WordPress 5.0+
- PHP 7.4+ (zalecane 8.0+)
- Rozszerzenie OpenSSL (opcjonalne, do szyfrowania)
- Klucz API Google Gemini

---

## Licencja

Proprietary - Antigravity

---