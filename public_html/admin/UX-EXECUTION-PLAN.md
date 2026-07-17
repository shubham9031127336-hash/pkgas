# 🧠 AI Assistant UX Execution Plan

**Generated:** 2026-05-25  
**Status:** Draft  
**Target Files:** `ai-assistant.js`, `ai-assistant.css`, `ai-assistant.php`, `orchestrator.php`, `response-builder.php`, `customers.php`

---

## 🔍 Current State Analysis

### 1. Output Style Variety
**What exists:** The frontend already has rich structured-data renderers in `ai-assistant.js`:
- `renderInventoryUI()` — stat cards for stock by gas type + cylinder status badges
- `renderSalesUI()` — revenue/order cards + weekly sales table + top customers table
- `renderCustomerUI()` — profile card (single) or table (multiple)
- `renderAnalyticsUI()` — snapshot cards + WoW/MoM comparison + low stock alerts + forecast + depletion table
- `renderChartBlock()` — Chart.js bar/line/pie/doughnut/doughnut
- `renderTableBlock()` — generic table
- `renderProfileBlock()` — generic profile card
- `renderComparisonBlock()` — comparison table with +/- coloring
- `renderTimelineBlock()` — vertical timeline with dots
- `renderStatsBlock()` — generic stat cards
- `renderInsightBlock()` — colored info/warning/success/danger alerts

**The problem:** These renderers are only triggered when the backend provides `visual_blocks` in the JSON response. The LLM prompt in `response-builder.php` doesn't consistently instruct the model to generate varied visual blocks for different scenarios. For similar query types, the LLM defaults to the same text-only output structure every time.

**Root cause:** The Phase 2 prompt in `response-builder.php` doesn't include clear examples of when to use chart vs table vs cards vs comparison vs timeline. The `visual_blocks` JSON schema instruction is weak — the LLM treats it as optional and often skips it.

### 2. Suggestion Chips for User Questions
**What exists:**
- Static initial chips: "Show Oxygen inventory", "How were sales this week?", "Find customer by mobile"
- Dynamic `updateFollowUpSuggestions()` based on intent (lines 763-799)
- `options` array in `addStructuredMessage()` renders as `option-chip` below message text (lines 908-919)
- Suggestion chips in PHP rendered as 3 `<span>` elements

**The problem:**
- Options are only shown if the backend returns them — the LLM doesn't consistently generate them
- No way to "click from audio" — only visual click
- Suggestions disappear during processing (`display: none`) instead of being replaced with contextual ones
- Only 3 chips max, which limits user choice

### 3. Microphone Stay-On
**What exists:**
- Uses Web Speech API (`SpeechRecognition`)
- `continuous: false` — stops after each utterance
- `interimResults: false`
- On result: fills input, auto-sends, stops mic
- Voice button toggle: clicking starts, clicking again stops

**The problem:**
- Mic stops after every utterance — user must click button again for each question
- No way to have a natural back-and-forth conversation
- The `onend` handler unconditionally removes listening state

---

## 🎯 Improvement Plan

### Goal 1: Diverse Output Styles Per Scenario

**Problem:** Same output style for similar queries.

**Solution — Frontend (`ai-assistant.js`):**
1. Add 3 new renderers:
   - `renderGaugeBlock(block)` — Circular gauge / speedometer for KPIs (fulfillment rate, utilization)
   - `renderKanbanBlock(block)` — Kanban-style column layout for status workflows (cylinder lifecycle)
   - `renderTimelineHorizontal(block)` — Horizontal timeline for order/invoice history
2. Extend `renderChartBlock()` to support horizontal bar charts and stacked bars
3. Add staggered animation delays for visual_blocks (already partially done via CSS delay classes)
4. Add color-palette rotation for cards so no two adjacent cards look identical
5. Add "Expand/Collapse" toggle on long tables (>8 rows)

**Solution — Backend (`response-builder.php` and `orchestrator.php`):**
1. Enhance Phase 2 system prompt with explicit scenario-to-visual-block mappings:
   - Inventory/stock → prefer `stats` + `table` + `chart` (pie for gas mix, bar for stock levels)
   - Sales → prefer `stats` (topline) + `chart` (trend line) + `comparison` (WoW)
   - Customer lookup → prefer `profile` (single) or `table` (multiple) + `stats`
   - Cylinder tracking → prefer `kanban` (status columns) + `table` (detailed list)
   - Invoice lookup → prefer `table` + `timeline` (payment history)
   - Analytics/forecast → prefer `chart` (line forecast) + `stats` + `insight` alerts
   - General → prefer `table` or `stats` depending on data shape
2. Add few-shot examples in the prompt showing real scenarios with their visual_blocks
3. Add a fallback `renderDefaultUI(data)` in JS that auto-detects data shape and picks best visual

### Goal 2: Clickable Suggestions with Audio Support

**Problem:** Suggestions are only visual, no audio selection, and not contextual enough.

**Solution — Frontend (`ai-assistant.js`):**
1. **Increase chip count** from 3 to 4 in the UI
2. **Numbered chips** — Each chip gets an index number (1-4) that the user can speak (e.g., "option 2") to select
3. **Add voice command parsing** — In the `recognition.onresult` handler, check if transcript matches "option [1-4]" or "choose [1-4]" or "select [1-4]" or just "[1-4]", and trigger `sendMessage()` on the corresponding suggestion chip
4. **Keep suggestions visible** during processing but fade-opacity 0.4 instead of `display: none`
5. **Add "Quick Actions" row** — A second row of chips above suggestions with fixed high-value actions: "📊 Dashboard", "📋 Inventory", "👥 Customers", "💰 Sales"
6. **Animated chip entry** — Chips slide in from bottom on update with stagger delay

**Solution — Backend (`response-builder.php`):**
1. Strengthen the `options` field in Phase 2 prompt: Always generate 3-4 follow-up options phrased as questions the user might ask next
2. Add SQL query result context to make options more data-driven (e.g., "Show details for [customer_name]" rather than generic "Find customer")

### Goal 3: Microphone Stay-On Until Conversation Ends

**Problem:** Mic stops after every utterance, breaking natural flow.

**Solution — Frontend (`ai-assistant.js`):**
1. Change `continuous: false` → `continuous: true`
2. Change `interimResults: false` → `interimResults: true` (shows partial text in input as user speaks)
3. Restructure the voice flow:
   - **Single click** on voice button → starts continuous listening
   - **On result:** Append transcript to input (don't auto-send), keep mic active
   - **User reviews text** in input, edits if needed, clicks Send or presses Enter
   - **Voice + Send combination:** If `lastInputWasVoice` is true and user clicks Send, send message and keep mic active
   - **Double-click** voice button OR say "stop" / "bye" / "that's all" → stops microphone
   - **Auto-stop** after 30 seconds of silence (configurable timeout)
4. **Visual indicator** — Show waveform animation instead of static "Listening..." text. Use canvas or CSS bars that pulse with audio level (if supported by browser).
5. **Interim text display** — Show live transcription in input field as user speaks, updating in real-time
6. **Session-end detection** — If user says "bye", "thank you", "that's all", "stop", or nothing for 60s, auto-end session and stop mic

---

## 📋 Implementation Order

| Priority | Task | Files | Effort |
|----------|------|-------|--------|
| P1 | Microphone continuous mode (stay-on) | `ai-assistant.js` | Medium |
| P2 | Backend visual_blocks prompt enhancement | `response-builder.php`, `orchestrator.php` | Medium |
| P3 | Voice command for suggestion chips (numbered) | `ai-assistant.js` | Small |
| P4 | New renderers: gauge, kanban, horizontal timeline | `ai-assistant.js`, `ai-assistant.css` | Medium |
| P5 | Quick Actions row + animated chip entry | `ai-assistant.php`, `ai-assistant.css` | Small |
| P6 | Expand/collapse for long tables | `ai-assistant.js`, `ai-assistant.css` | Small |
| P7 | Waveform animation for mic active state | `ai-assistant.css` | Small |

---

## ✅ Success Criteria

1. **Output variety:** Running the same query twice in different sessions produces visually different layouts (cards vs table vs chart)
2. **Suggestion chips:** User can say "option 2" and the AI picks the second chip
3. **Microphone:** Mic stays on for entire conversation (5+ exchanges), only stops on "stop"/"bye" command or button double-click
4. **Visual polish:** All new elements have staggered fade-up animations, consistent with existing design system
5. **No regressions:** Existing text-only responses still render correctly, streaming still works, feedback modal still works
