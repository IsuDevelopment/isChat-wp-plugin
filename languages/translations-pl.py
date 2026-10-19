"""
Polish translations for IsChat, and the generator for every catalogue file.

Edit PL below, then from the plugin root run:

    uv run --with polib python languages/translations-pl.py

It rewrites languages/ai-ischat.pot, ai-ischat-pl_PL.po/.mo and the per-script
JSON files the block editor reads (named by md5 of the built script's path,
like `wp i18n make-json` does). A source string with no entry here fails the
run, so new strings can't ship untranslated by accident.
"""

import hashlib
import json
import pathlib
import re
import sys

import polib

ROOT = pathlib.Path(__file__).resolve().parent.parent
LANG = ROOT / "languages"
DOMAIN = "ai-ischat"
LOCALE = "pl_PL"

# Source JS file -> built script WordPress actually loads.
JS_BUILDS = {
    "src/index-panel/index.js": "build/index-panel/index.js",
    "src/chat/index.js": "build/chat/index.js",
    "src/search/index.js": "build/search/index.js",
}

# Read by register_block_type() with these contexts.
BLOCK_JSON_CONTEXT = {"title": "block title", "description": "block description"}

PL = {
    # Settings page
    "IsChat": "IsChat",
    "Settings sections": "Sekcje ustawień",
    "Connection": "Połączenie",
    "Content": "Treści",
    "Knowledge Base": "Baza wiedzy",
    "API Connection": "Połączenie z API",
    "API Key": "Klucz API",
    "Site ID": "ID witryny",
    "Site Key": "Klucz witryny",
    "Secret API key — copy it from the IsChat panel → Sites → your site → API Key.":
        "Tajny klucz API — skopiuj go z panelu IsChat → Witryny → Twoja witryna → Klucz API.",
    "Numeric site ID from the IsChat dashboard.": "Numeryczne ID witryny z panelu IsChat.",
    "Public site key from the IsChat panel (e.g. site_testkey123). Used by the chat widget and the IsChat blocks.":
        "Publiczny klucz witryny z panelu IsChat (np. site_testkey123). Używany przez widżet czatu i bloki IsChat.",
    "Save Connection Settings": "Zapisz ustawienia połączenia",
    "Actions": "Akcje",
    "Save your settings first, then use these actions to verify the connection or push all content to the knowledge base.":
        "Najpierw zapisz ustawienia, a potem użyj tych akcji, aby sprawdzić połączenie lub wysłać wszystkie treści do bazy wiedzy.",
    "Test Connection": "Testuj połączenie",
    "Sync All Content": "Synchronizuj wszystkie treści",
    "Queue Status": "Stan kolejki",
    "Pending jobs": "Zadania oczekujące",
    "Failed jobs": "Zadania nieudane",
    "Next cron run": "Następne uruchomienie crona",
    "in %s": "za %s",
    "Not scheduled": "Nie zaplanowano",
    "Indexing": "Indeksowanie",
    "Post Types to Index": "Typy treści do indeksowania",
    'Selected post types become eligible for AI indexing, but each post still requires explicit "Index in IsChat" opt-in and a manual indexing action.':
        "Wybrane typy treści mogą być indeksowane przez AI, ale każdy wpis nadal wymaga włączenia opcji „Indeksuj w IsChat” i ręcznego zaindeksowania.",
    "Chat Widget": "Widżet czatu",
    "Enable Chat Widget": "Włącz widżet czatu",
    "Inject the chat widget script on all public-facing pages": "Dodaj skrypt widżetu czatu na wszystkich publicznych stronach",
    "Requires the Site Key on the Connection tab.": "Wymaga klucza witryny w zakładce Połączenie.",
    "Save Content Settings": "Zapisz ustawienia treści",
    "Manual Knowledge Base": "Ręczna baza wiedzy",
    "Add custom knowledge to your chatbot — FAQs, company facts, product details, or any text you want the AI to know about. This content is stored as a single document in the knowledge base.":
        "Dodaj własną wiedzę dla chatbota — FAQ, informacje o firmie, szczegóły produktów lub dowolny tekst, który AI ma znać. Treść jest zapisywana jako jeden dokument w bazie wiedzy.",
    "Knowledge Base Content": "Treść bazy wiedzy",
    "Plain text only. Write clearly — the AI will use this verbatim. Each save replaces the previous content.":
        "Tylko czysty tekst. Pisz jasno — AI użyje go dosłownie. Każdy zapis zastępuje poprzednią treść.",
    "Save &amp; Sync to Knowledge Base": "Zapisz i synchronizuj z bazą wiedzy",
    "Save & Sync to Knowledge Base": "Zapisz i synchronizuj z bazą wiedzy",
    "Sync queue: %1$d pending, %2$d failed.": "Kolejka synchronizacji: %1$d oczekujących, %2$d nieudanych.",
    "Plugin is not fully configured. Please fill in the API Key and Site ID on the Connection tab.":
        "Wtyczka nie jest w pełni skonfigurowana. Uzupełnij klucz API i ID witryny w zakładce Połączenie.",
    "Testing…": "Testowanie…",
    "Syncing…": "Synchronizowanie…",
    "Saving…": "Zapisywanie…",
    "Request failed. Please try again.": "Żądanie nie powiodło się. Spróbuj ponownie.",
    "Connection failed.": "Połączenie nie powiodło się.",
    "Sync failed.": "Synchronizacja nie powiodła się.",
    "Save failed.": "Zapis nie powiódł się.",
    "Plan:": "Plan:",
    "Documents:": "Dokumenty:",
    "Syncs today:": "Synchronizacje dzisiaj:",
    "This will queue all published content for re-sync. Continue?":
        "Wszystkie opublikowane treści zostaną dodane do kolejki ponownej synchronizacji. Kontynuować?",

    # Admin (AJAX, list table, notices)
    "AI Indexing": "Indeksowanie AI",
    "Enable AI indexing": "Włącz indeksowanie AI",
    "Disable AI indexing": "Wyłącz indeksowanie AI",
    "Queue force sync": "Dodaj wymuszoną synchronizację do kolejki",
    "The indexing action failed. Please try again.": "Akcja indeksowania nie powiodła się. Spróbuj ponownie.",
    "Indexed": "Zaindeksowano",
    "Sync pending": "Oczekuje na synchronizację",
    "Disabled": "Wyłączone",
    "Never synced": "Nigdy nie synchronizowano",
    "Last sync: %s": "Ostatnia synchronizacja: %s",
    "Enable IsChat AI Indexing": "Włącz indeksowanie AI IsChat",
    "Disable IsChat AI Indexing": "Wyłącz indeksowanie AI IsChat",
    "Queue IsChat force sync": "Wymuś synchronizację IsChat",
    "Permission denied.": "Brak uprawnień.",
    "Plugin is not fully configured. Please fill in the API Key and Site ID.":
        "Wtyczka nie jest w pełni skonfigurowana. Uzupełnij klucz API i ID witryny.",
    "Connection successful!": "Połączenie działa!",
    "Plugin is not fully configured. Please check the Connection settings.":
        "Wtyczka nie jest w pełni skonfigurowana. Sprawdź ustawienia połączenia.",
    "Compared all AI-enabled content: %1$d changes queued, %2$d processed now. Remaining items will sync automatically in the background.":
        "Porównano wszystkie treści z włączonym AI: %1$d zmian w kolejce, %2$d przetworzono teraz. Pozostałe zsynchronizują się automatycznie w tle.",
    "AI indexing enabled. Sync queued.": "Indeksowanie AI włączone. Synchronizacja w kolejce.",
    "AI indexing disabled. Removal queued.": "Indeksowanie AI wyłączone. Usunięcie w kolejce.",
    "Enable AI indexing before forcing a sync.": "Włącz indeksowanie AI przed wymuszeniem synchronizacji.",
    "Force sync completed.": "Wymuszona synchronizacja zakończona.",
    "Invalid indexing action.": "Nieprawidłowa akcja indeksowania.",
    "Manual knowledge base saved locally (not synced — connection settings are incomplete).":
        "Ręczna baza wiedzy zapisana lokalnie (bez synchronizacji — ustawienia połączenia są niekompletne).",
    "Manual knowledge base saved and synced successfully.": "Ręczna baza wiedzy zapisana i zsynchronizowana.",
    "Invalid request.": "Nieprawidłowe żądanie.",
    "AI indexing enabled and sync queued.": "Indeksowanie AI włączone, synchronizacja w kolejce.",
    "AI indexing disabled and removal from the index queued.": "Indeksowanie AI wyłączone, usunięcie z indeksu w kolejce.",
    ("%d item updated.", "%d items updated."): ["Zaktualizowano %d element.", "Zaktualizowano %d elementy.", "Zaktualizowano %d elementów."],

    # Core plugin / sync / WooCommerce
    "Post not found.": "Nie znaleziono wpisu.",
    "Plugin is not configured (missing API key or Site ID).": "Wtyczka nie jest skonfigurowana (brak klucza API lub ID witryny).",
    "Every 5 minutes": "Co 5 minut",
    "Plugin is not fully configured.": "Wtyczka nie jest w pełni skonfigurowana.",
    "Post indexed successfully.": "Wpis zaindeksowany.",
    'This post is not eligible for AI indexing. Enable "Index in IsChat", use a supported post type, and keep the post published.':
        "Ten wpis nie może być indeksowany przez AI. Włącz „Indeksuj w IsChat”, użyj obsługiwanego typu treści i pozostaw wpis opublikowany.",
    "Invalid offer verification signature.": "Nieprawidłowy podpis weryfikacji oferty.",
    "Offer verification request was already used.": "To żądanie weryfikacji oferty zostało już użyte.",
    "Provide between one and six products.": "Podaj od jednego do sześciu produktów.",
    "WooCommerce is unavailable.": "WooCommerce jest niedostępny.",

    # Block editor — index panel
    "Indexing failed. Check plugin configuration.": "Indeksowanie nie powiodło się. Sprawdź konfigurację wtyczki.",
    "Index in IsChat": "Indeksuj w IsChat",
    "Extra AI description": "Dodatkowy opis dla AI",
    "Additional context for the AI — not visible on the page.": "Dodatkowy kontekst dla AI — niewidoczny na stronie.",
    "Indexed:": "Zaindeksowano:",
    "Last indexed:": "Ostatnie indeksowanie:",
    "Last modified:": "Ostatnia modyfikacja:",
    "Content changed since last index.": "Treść zmieniła się od ostatniego indeksowania.",
    "Indexed successfully.": "Zaindeksowano.",
    "Indexing…": "Indeksowanie…",
    "Manual Index Now": "Zaindeksuj teraz",
    "Save the post first to apply changes.": "Najpierw zapisz wpis, aby zastosować zmiany.",
    "Manual indexing is available for published posts only.": "Ręczne indeksowanie jest dostępne tylko dla opublikowanych wpisów.",

    # Blocks
    "IsChat Chat": "Czat IsChat",
    "IsChat Search": "Wyszukiwarka IsChat",
    "Example questions": "Przykładowe pytania",
    "Questions": "Pytania",
    "One question per line, up to 5. Leave empty to use the questions configured for this site.":
        "Jedno pytanie w wierszu, maksymalnie 5. Zostaw puste, aby użyć pytań skonfigurowanych dla tej witryny.",
    "Chat widget will appear here on the frontend.": "Widżet czatu pojawi się tutaj na stronie.",
    "AI search widget will appear here on the frontend.": "Wyszukiwarka AI pojawi się tutaj na stronie.",
    "Inline chat window powered by IsChat. Visitors can chat directly on the page.":
        "Okno czatu IsChat osadzone na stronie. Odwiedzający mogą rozmawiać bezpośrednio na stronie.",
    "Inline contextual AI search powered by IsChat.": "Kontekstowa wyszukiwarka AI IsChat osadzona na stronie.",
    "Inline contextual AI search block powered by IsChat.": "Blok kontekstowej wyszukiwarki AI IsChat.",
}

# --- extraction ------------------------------------------------------------

CALL = re.compile(
    r"""\b(__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*(['"])((?:\\.|(?!\2).)*)\2\s*,\s*['"]""" + DOMAIN,
    re.S,
)
PLURAL = re.compile(
    r"""\b_n\(\s*(['"])((?:\\.|(?!\1).)*)\1\s*,\s*(['"])((?:\\.|(?!\3).)*)\3""",
    re.S,
)
SKIP_DIRS = {"vendor", "node_modules", "build", "tests", "languages"}


def unquote(s: str, quote: str) -> str:
    # PHP single quotes only unescape \' and \\; JS/double quotes the usual.
    return s.replace("\\" + quote, quote).replace("\\\\", "\\")


def extract():
    """Return {msgid_or_(singular, plural): (context, {relative source files})}."""
    found = {}
    for path in sorted(ROOT.rglob("*")):
        if path.suffix not in (".php", ".js") or SKIP_DIRS & set(path.relative_to(ROOT).parts):
            continue
        rel = str(path.relative_to(ROOT))
        text = path.read_text(encoding="utf-8")
        for m in CALL.finditer(text):
            found.setdefault((None, unquote(m.group(3), m.group(2))), set()).add(rel)
        for m in PLURAL.finditer(text):
            key = (unquote(m.group(2), m.group(1)), unquote(m.group(4), m.group(3)))
            found.setdefault((None, key), set()).add(rel)
    for block_json in sorted((ROOT / "src").glob("*/block.json")):
        meta = json.loads(block_json.read_text(encoding="utf-8"))
        for field, ctx in BLOCK_JSON_CONTEXT.items():
            if meta.get(field):
                found.setdefault((ctx, meta[field]), set()).add(str(block_json.relative_to(ROOT)))
    return found


def main() -> int:
    found = extract()
    missing = [key for _, key in found if key not in PL]
    if missing:
        print("Missing Polish translations:", *missing, sep="\n  ", file=sys.stderr)
        return 1

    header = {
        "Project-Id-Version": "IsChat",
        "MIME-Version": "1.0",
        "Content-Type": "text/plain; charset=UTF-8",
        "Content-Transfer-Encoding": "8bit",
        "X-Domain": DOMAIN,
    }
    pot = polib.POFile(wrapwidth=0)
    pot.metadata = dict(header)
    po = polib.POFile(wrapwidth=0)
    po.metadata = {
        **header,
        "Language": LOCALE,
        "Plural-Forms": "nplurals=3; plural=(n==1 ? 0 : n%10>=2 && n%10<=4 && (n%100<12 || n%100>14) ? 1 : 2);",
    }
    js = {build: {} for build in JS_BUILDS.values()}

    for (ctx, key), files in sorted(found.items(), key=lambda item: str(item[0][1])):
        refs = [(f, "") for f in sorted(files)]
        if isinstance(key, tuple):
            singular, plural = key
            pot.append(polib.POEntry(msgctxt=ctx, msgid=singular, msgid_plural=plural, msgstr_plural={0: "", 1: ""}, occurrences=refs))
            po.append(polib.POEntry(msgctxt=ctx, msgid=singular, msgid_plural=plural,
                                    msgstr_plural=dict(enumerate(PL[key])), occurrences=refs))
            continue
        pot.append(polib.POEntry(msgctxt=ctx, msgid=key, msgstr="", occurrences=refs))
        po.append(polib.POEntry(msgctxt=ctx, msgid=key, msgstr=PL[key], occurrences=refs))
        for src, build in JS_BUILDS.items():
            if src in files:
                js[build][key] = [PL[key]]

    pot.save(LANG / f"{DOMAIN}.pot")
    po.save(LANG / f"{DOMAIN}-{LOCALE}.po")
    po.save_as_mofile(LANG / f"{DOMAIN}-{LOCALE}.mo")

    for build, messages in js.items():
        payload = {
            "domain": "messages",
            "locale_data": {"messages": {"": {"domain": "messages", "lang": LOCALE, "plural-forms": po.metadata["Plural-Forms"]}, **messages}},
        }
        name = f"{DOMAIN}-{LOCALE}-{hashlib.md5(build.encode()).hexdigest()}.json"
        (LANG / name).write_text(json.dumps(payload, ensure_ascii=False, indent="\t") + "\n", encoding="utf-8")

    print(f"{len(found)} strings, {sum(map(len, js.values()))} in JS.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
