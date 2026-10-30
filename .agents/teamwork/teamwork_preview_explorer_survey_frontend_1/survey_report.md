# Frontend UI/UX Architecture & Survey Report

**Date**: 2026-10-02  
**Role**: Frontend UI/UX Explorer  
**Project**: Poster (Laravel 13 + React 18 + Inertia v2)  
**Document Target**: `.agents/teamwork/teamwork_preview_explorer_survey_frontend_1/survey_report.md`  

---

## 1. Executive Summary

This survey report provides the architectural analysis and implementation specifications for the frontend refactoring of the **Poster** multi-channel publishing system. Specifically, it details the requirements for:
- **R2: UI & UX Refactoring** (Publishing Stepper/Progress Bar, Publishing Timeline Audit Log, Manual Per-Channel Retry, Autosave Drafts, Duplicate Post, and Facebook PDF Exclusion in the Media Picker).
- **R4: Realtime Progress** (Robust ~1.5s near-realtime polling during `queued`, `processing`, and `retrying` states, with clean auto-termination on terminal states `published`, `failed`, and `partial`).

### Baseline Health Check
- **Vite Build**: Executed `npm run build` cleanly (3,277 modules transformed, exited with code 0 in 7.10s).
- **Frontend Stack**: React 18.2.0, Inertia.js v2 (`@inertiajs/react` 2.0.0), Vite 8.3.1, Tailwind CSS 3.2.1 (with `@tailwindcss/forms` and `@tailwindcss/typography`), Headless UI v2 (`@headlessui/react`), Lucide React (`lucide-react` 1.49.0), SweetAlert2 (`sweetalert2` 11.26.25), Tiptap v3.31.4 rich text editor, React Datepicker v9.1.0 with Thai locale, Axios 1.20.0, and Ziggy routes (`@routes`).
- **Typography & Theme**: Thai font `Sarabun` is globally configured as the primary font family in `resources/css/app.css` and `tailwind.config.js`. Custom theme colors include `navy` (`#000080`, `#1a1a99`, `#000066`) and `gold` (`#FFD700`, `#FFE44D`, `#CCAC00`).

---

## 2. Current Frontend Architecture & Inventory

### 2.1 File & Directory Map

```
resources/
├── css/
│   └── app.css                             # Sarabun font import, Tailwind directives, #F8FAFC background
├── js/
│   ├── app.jsx                             # Inertia createInertiaApp bootstrap with dynamic import
│   ├── bootstrap.js                        # Axios default configuration
│   ├── Layouts/
│   │   ├── AuthenticatedLayout.jsx         # Main dashboard layout, nav links, SweetAlert2 flash listener
│   │   └── GuestLayout.jsx                 # Auth pages layout (login, registration)
│   ├── Components/
│   │   ├── ApplicationLogo.jsx             # SVG application branding logo
│   │   ├── Checkbox.jsx                    # Styled checkbox input
│   │   ├── DangerButton.jsx                # Red destructive action button
│   │   ├── DateTimePicker.jsx              # Datepicker with Thai Buddhist calendar & 24h time picker
│   │   ├── Dropdown.jsx                    # Headless UI dropdown wrapper
│   │   ├── InputError.jsx                  # Form validation error text
│   │   ├── InputLabel.jsx                  # Form field label
│   │   ├── Modal.jsx                       # Headless UI Dialog & Transition modal wrapper
│   │   ├── NavLink.jsx                     # Desktop top navigation active link
│   │   ├── ResponsiveNavLink.jsx           # Mobile drawer navigation active link
│   │   ├── PostForm.jsx                    # Monolithic post editor form (400 lines)
│   │   ├── PrimaryButton.jsx               # Navy blue primary button
│   │   ├── RichTextEditor.jsx              # Tiptap wysiwyg editor with toolbar & HTML output
│   │   ├── SecondaryButton.jsx             # Bordered secondary button
│   │   └── TextInput.jsx                   # Standard styled text input
│   └── Pages/
│       ├── Dashboard.jsx                   # Summary statistics cards & recent posts table
│       ├── Posts/
│       │   ├── Index.jsx                   # Post list table, basic 2s polling, edit/delete actions
│       │   ├── Create.jsx                  # Wrapper for PostForm (create mode)
│       │   └── Edit.jsx                    # Wrapper for PostForm (edit mode)
│       ├── Media/
│       │   └── Index.jsx                   # Full media library, drag-and-drop upload, filter tabs
│       ├── Settings/
│       │   └── Integrations.jsx            # Facebook & WordPress credentials form & health check triggers
│       └── Templates/
│           ├── Index.jsx, Create.jsx, Edit.jsx # Template management for prefilled post content
└── views/
    └── app.blade.php                       # Root Blade template with @routes, @viteReactRefresh, @inertia
```

### 2.2 Critical Gaps in Current Implementation

| Component / Area | Existing Behavior | Required Behavior (R2 & R4) |
|---|---|---|
| **Post List (`Posts/Index.jsx`)** | Polling hardcoded to 2000ms; only checks `post.status === 'publishing'` or `targets.status in ['pending', 'processing']`. | Update polling to **~1500ms** (~1.5s); check `queued`, `processing`, and `retrying`; terminate cleanly on `published`, `failed`, `partial`. |
| **Actions Column (`Posts/Index.jsx`)** | Only "แก้ไข" (Edit) and "ลบ" (Delete). | Add "ทำสำเนา" (Duplicate), "ดูประวัติ" (Publishing Timeline), and per-channel "ลองใหม่" (Retry). |
| **Publishing Feedback (`PostForm.jsx`)** | Clicking "เผยแพร่ทันที" submits the form and navigates directly back to `posts.index`. User has no feedback on what stage of publishing is happening. | Launch a **Publishing Stepper / Progress Bar Modal** that keeps the user informed in real time with step-by-step progress per channel. |
| **Audit Log / Timeline** | `publish_attempts` and `publish_events` DB tables and models exist, but no UI view exists to inspect them. | Add a **Publishing Timeline Modal / Drawer** showing channel-specific status, timestamps, attempts, durations, and Thai error messages. |
| **Manual Channel Retry** | Failed channel requires re-saving the entire post or editing content, risking duplicate publishing on already-succeeded channels. | Provide a one-click **Manual Retry Action per Channel** (`POST /posts/{post}/retry/{channel}`) with optimistic status update. |
| **Draft Autosave (`PostForm.jsx`)** | User must manually scroll to bottom and click "บันทึกร่าง". Unsaved changes are lost on accidental navigation or crash. | Add **Autosave Drafts** (3s debounce or 30s interval) with subtle UI status indicators ("บันทึกร่างเมื่อ 10:45 น."). |
| **Duplicate Post** | Non-existent. Users must manually re-type or copy-paste content across posts. | Add **Duplicate Post** action copying content, category, and featured media into a new draft post with fresh targets. |
| **Media Picker PDF Handling** | Hardcoded client filter in `PostForm` only checks `m.mime_type.startsWith('image/')` for thumbnail viewer; upload checks file type on upload with basic `alert()`. | When Facebook is selected, strictly filter out PDFs from the Media Picker and file upload input, displaying a Thai guidance modal. |

---

## 3. Detailed Specifications for R2 (UI & UX Refactoring)

### 3.1 Publishing Progress UI (Stepper / Progress Bar Component)

#### UX Flow & Placement
When a user clicks **"เผยแพร่ทันที"** (Publish Now) in `PostForm.jsx`:
1. The post is submitted to the backend (`POST /posts` or `PUT /posts/{id}` with `publish_action: 'publish'`).
2. Instead of navigating away immediately, the UI displays a `PublishingProgressModal`.
3. The modal initiates near-realtime polling (`GET /posts/{id}/status` or Inertia reload) every ~1.5 seconds.
4. The user sees a live animated progress bar and dual-channel step-by-step indicators.
5. Upon reaching a terminal state:
   - **All Successful (`published`)**: Confetti/green celebration check, direct links to view the remote posts (`external_url` on WordPress and Facebook), and a button to "กลับสู่หน้ารายการข่าว".
   - **Partial Success (`partially_published`)**: Amber alert banner indicating which channel succeeded and which failed, with a 1-click "ลองใหม่เฉพาะ [Channel]" retry button.
   - **Total Failure (`failed`)**: Red alert banner with the classified Thai error message and an option to retry or edit.
6. The user can click "ดำเนินการต่อในเบื้องหลัง" (Continue in background) at any time to close the modal and return to `posts.index`.

#### Component Architecture: `PublishingProgressModal.jsx`
```jsx
// resources/js/Components/Publishing/PublishingProgressModal.jsx
import Modal from '@/Components/Modal';
import PublishingStepper from '@/Components/Publishing/PublishingStepper';
import ChannelRetryButton from '@/Components/Publishing/ChannelRetryButton';
import { CheckCircle2, AlertCircle, RefreshCw, ExternalLink, X } from 'lucide-react';

export default function PublishingProgressModal({
    show,
    post,
    progressData, // { status, targets, progress_percentage, attempts, events }
    onClose,
    onRetryChannel,
}) {
    const isCompleted = ['published', 'failed', 'partially_published'].includes(progressData?.status);
    const isSuccess = progressData?.status === 'published';
    const isPartial = progressData?.status === 'partially_published';
    const isFailed = progressData?.status === 'failed';

    return (
        <Modal show={show} onClose={onClose} maxWidth="xl">
            <div className="p-6">
                <div className="flex items-center justify-between border-b pb-4">
                    <div>
                        <h3 className="text-lg font-bold text-gray-900">
                            {isCompleted ? 'ผลการเผยแพร่ข่าว' : 'กำลังดำเนินการเผยแพร่...'}
                        </h3>
                        <p className="text-xs text-gray-500 truncate max-w-md">{post?.title}</p>
                    </div>
                    <button onClick={onClose} className="text-gray-400 hover:text-gray-600">
                        <X className="w-5 h-5" />
                    </button>
                </div>

                {/* Overall Progress Bar */}
                <div className="mt-4">
                    <div className="flex justify-between text-xs text-gray-600 mb-1">
                        <span>ความคืบหน้า</span>
                        <span>{progressData?.progress_percentage || 0}%</span>
                    </div>
                    <div className="w-full bg-gray-200 rounded-full h-2.5 overflow-hidden">
                        <div
                            className={`h-2.5 rounded-full transition-all duration-500 ${
                                isSuccess ? 'bg-emerald-500' : isFailed ? 'bg-rose-500' : isPartial ? 'bg-amber-500' : 'bg-[#000080]'
                            }`}
                            style={{ width: `${progressData?.progress_percentage || 15}%` }}
                        />
                    </div>
                </div>

                {/* Dual Channel Stepper */}
                <div className="mt-6 space-y-4">
                    {progressData?.targets?.map((target) => (
                        <PublishingStepper
                            key={target.id}
                            target={target}
                            onRetry={() => onRetryChannel(target.channel)}
                        />
                    ))}
                </div>

                {/* Footer Controls */}
                <div className="mt-6 pt-4 border-t flex justify-end gap-3">
                    <button
                        type="button"
                        onClick={onClose}
                        className="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg"
                    >
                        {isCompleted ? 'ปิดหน้าต่าง' : 'ทำงานในเบื้องหลัง'}
                    </button>
                </div>
            </div>
        </Modal>
    );
}
```

#### Channel Step Definition: `PublishingStepper.jsx`
- **WordPress Steps**:
  1. `queued`: "กำลังจัดเตรียมข้อมูลเข้าคิวงาน" (In Queue)
  2. `processing`: "กำลังอัปโหลดรูปภาพและส่งเนื้อหาไปยัง WordPress" (Sending Content & Media)
  3. `published`: "เผยแพร่บน WordPress เรียบร้อยแล้ว" (Published) -> includes link button to `external_url`
  4. `failed`: "เกิดข้อผิดพลาดในการเผยแพร่บน WordPress" (Failed) -> displays Thai error message + Retry button
- **Facebook Steps**:
  1. `queued`: "กำลังจัดเตรียมข้อมูลเข้าคิวงาน" (In Queue)
  2. `waiting_wp`: "รอลิงก์บทความจาก WordPress (หากมีการระบุ {wordpress_url})"
  3. `processing`: "กำลังอัปโหลดรูปภาพและโพสต์ข้อความบน Facebook Page"
  4. `commenting`: "กำลังส่งคอมเมนต์แรกใต้โพสต์ (First Comment)"
  5. `published`: "เผยแพร่บน Facebook Page เรียบร้อยแล้ว" (Published) -> includes link button to `external_url`
  6. `failed`: "เกิดข้อผิดพลาดในการเผยแพร่บน Facebook" (Failed) -> displays Thai error message + Retry button

---

### 3.2 Publishing Timeline (Audit Log)

#### Data Mapping & Schema
The timeline draws directly from the `publish_attempts` and `publish_events` tables.
```
PublishAttempt:
- id
- channel: "wordpress" | "facebook"
- attempt_number: 1, 2, 3...
- status: "queued" | "processing" | "published" | "failed" | "retrying"
- queued_at, started_at, finished_at
- duration_ms: e.g. 1420 (display as "1.42 วินาที")
- error_type: "auth_required" | "permission_denied" | "rate_limited" | "network_error" | "validation" | "server_error"
- error_code: e.g. 190, 200, 4, "rest_not_logged_in"
- error_message: Human-readable Thai message mapped by PublishFailureClassifier
- retryable: boolean
- metadata: JSON containing sanitized request/response details
```

#### Timeline Modal UI Layout: `PublishingTimelineModal.jsx`
- **Header**: Post Title, Overall Status Badge, Total Attempts Counter, Close Button.
- **Channel Tabs / Accordion**: Filter logs by "ทั้งหมด" (All), "WordPress", or "Facebook".
- **Chronological Audit Trail**:
  - Vertical line connecting nodes (emerald for success, rose for failure, blue for in-progress, amber for retry).
  - Node content:
    - Attempt Header: `ครั้งที่ ${attempt.attempt_number} • ${channelName}`
    - Timestamp & Duration: `${formatDate(attempt.started_at)} • ใช้เวลา ${attempt.duration_ms ? (attempt.duration_ms / 1000).toFixed(2) + ' วินาที' : '-'}`
    - Status Badge: `สำเร็จ` (Published), `กำลังส่งใหม่` (Retrying), `ล้มเหลว` (Failed).
    - If Failed:
      - Prominent Thai Error Box: `สิทธิ์ Facebook ไม่เพียงพอ กรุณาตรวจสอบว่า Access Token มีสิทธิ์ pages_manage_posts`
      - Retry Eligibility Indicator: `สามารถลองใหม่ได้ (ระบบจะลองใหม่ตามรอบ)` vs `ข้อผิดพลาดถาวร (ต้องแก้ไขการตั้งค่า)`
      - Collapsible Technical Details (Developer Accordion):
        - HTTP Status: `401 Unauthorized`
        - Error Code: `190` (Subcode: `463`)
        - Correlation ID: `9a8b7c6d-...`
    - Action: If the latest attempt for a channel is `failed`, render an immediate **"ลองใหม่ทันที (Retry)"** button.

---

### 3.3 Manual Retry Action Per Channel

#### Business Rules
1. **Idempotency Guarantee**: If a channel already published and received an `external_id`, retrying must NEVER duplicate the remote post. The backend publisher checks for `external_id` and calls `update()` instead of `create()`.
2. **Channel Isolation**: Retrying Facebook must not trigger or affect a successful WordPress post, and vice versa.
3. **Optimistic Feedback**: When the user clicks "ลองใหม่":
   - The button shows a spinner and disables.
   - The target's status in the UI transitions immediately to `queued`.
   - Near-realtime polling (~1.5s) resumes automatically.
   - A toast notification informs the user: `"กำลังเริ่มการส่งข้อมูลไปยัง [ช่องทาง] ใหม่อีกครั้ง..."`.

#### API Trigger & React Component
- **Endpoint**: `POST /posts/{post}/retry/{channel}`
- **Payload**: `{}`
- **Component**: `ChannelRetryButton.jsx`
```jsx
// resources/js/Components/Publishing/ChannelRetryButton.jsx
import { useState } from 'react';
import { RotateCcw } from 'lucide-react';
import axios from 'axios';
import Swal from 'sweetalert2';

export default function ChannelRetryButton({ postId, channel, onRetried }) {
    const [loading, setLoading] = useState(false);

    const handleRetry = async (e) => {
        e.stopPropagation();
        setLoading(true);
        try {
            const response = await axios.post(`/posts/${postId}/retry/${channel}`);
            Swal.fire({
                icon: 'success',
                title: 'เริ่มการส่งข้อมูลใหม่แล้ว',
                text: `ระบบกำลังดำเนินการส่งข้อมูลไปยัง ${channel === 'facebook' ? 'Facebook' : 'WordPress'} อีกครั้ง`,
                toast: true,
                position: 'top-end',
                timer: 2500,
                showConfirmButton: false,
            });
            if (onRetried) onRetried(response.data);
        } catch (error) {
            Swal.fire({
                icon: 'error',
                title: 'ไม่สามารถลองใหม่ได้',
                text: error.response?.data?.message || 'เกิดข้อผิดพลาดในการเชื่อมต่อ',
                confirmButtonColor: '#000080',
            });
        } finally {
            setLoading(false);
        }
    };

    return (
        <button
            type="button"
            onClick={handleRetry}
            disabled={loading}
            className="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-semibold text-rose-700 bg-rose-50 border border-rose-200 rounded-md hover:bg-rose-100 disabled:opacity-50 transition"
        >
            <RotateCcw className={`w-3.5 h-3.5 ${loading ? 'animate-spin' : ''}`} />
            {loading ? 'กำลังส่ง...' : `ลองใหม่ (${channel})`}
        </button>
    );
}
```

---

### 3.4 Autosave Drafts Functionality

#### Technical Design & State Machine
- **Trigger**: 3-second debounce after input modifications (`title`, `wp_content`, `fb_caption`, `fb_comment`, `featured_image_id`, `wp_category_id`).
- **Minimum Criteria**: Title is not blank (at least 2 characters) AND form has unsaved edits (`isDirty`).
- **Safety**: Autosave is suspended when the user actively triggers "เผยแพร่ทันที" or "ตั้งเวลาเผยแพร่" to prevent race conditions.
- **Mode Transition (`Create` to `Edit`)**:
  - When a user starts on `Posts/Create` and the first autosave fires, the backend creates a draft record and returns `{ post_id: 105, slug: '...' }`.
  - The frontend dynamically updates `postId` and silently swaps the browser URL from `/posts/create` to `/posts/105/edit` using `window.history.replaceState` without a page reload. Subsequent autosaves send `PUT /posts/105/autosave`.

#### Custom Hook: `useAutosave.js`
```javascript
// resources/js/Hooks/useAutosave.js
import { useState, useEffect, useRef } from 'react';
import axios from 'axios';

export default function useAutosave({ data, initialPostId, enabled = true }) {
    const [postId, setPostId] = useState(initialPostId || null);
    const [status, setStatus] = useState('idle'); // 'idle' | 'unsaved' | 'saving' | 'saved' | 'error'
    const [lastSavedAt, setLastSavedAt] = useState(null);
    const isFirstRender = useRef(true);
    const lastSavedData = useRef(JSON.stringify(data));

    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;
            return;
        }

        if (!enabled) return;

        // Skip if title is empty
        if (!data.title || data.title.trim().length < 2) {
            setStatus('idle');
            return;
        }

        // Skip if content has not actually changed
        const currentDataString = JSON.stringify(data);
        if (currentDataString === lastSavedData.current) {
            return;
        }

        setStatus('unsaved');

        const handler = setTimeout(async () => {
            setStatus('saving');
            try {
                const payload = {
                    ...data,
                    publish_action: 'draft',
                    is_autosave: true,
                };

                let response;
                if (!postId) {
                    response = await axios.post('/posts/autosave', payload);
                    if (response.data?.post_id) {
                        setPostId(response.data.post_id);
                        window.history.replaceState(null, '', `/posts/${response.data.post_id}/edit`);
                    }
                } else {
                    response = await axios.put(`/posts/${postId}/autosave`, payload);
                }

                lastSavedData.current = currentDataString;
                setStatus('saved');
                setLastSavedAt(new Date());
            } catch (err) {
                console.error('Autosave error:', err);
                setStatus('error');
            }
        }, 3000); // 3-second debounce

        return () => clearTimeout(handler);
    }, [data, enabled, postId]);

    return { postId, status, lastSavedAt };
}
```

#### UI Status Indicator
Rendered inside the `PostForm` header:
- `idle`: (Hidden)
- `unsaved`: Yellow dot `● มีการเปลี่ยนแปลงที่ยังไม่ได้บันทึก`
- `saving`: Blue spinner `กำลังบันทึกร่างอัตโนมัติ...`
- `saved`: Green check `✓ บันทึกร่างอัตโนมัติแล้ว เมื่อ 14:32 น.`
- `error`: Red alert `! ไม่สามารถบันทึกร่างอัตโนมัติได้`

---

### 3.5 Duplicate Post Functionality

#### UX Flow
1. User clicks **"ทำสำเนา"** (Duplicate) in `Posts/Index.jsx` row actions or in `Posts/Edit.jsx` top toolbar.
2. A confirmation dialog appears:
   - *"ต้องการทำสำเนาโพสต์ '[ชื่อโพสต์]' หรือไม่?"*
3. On confirmation:
   - Client sends `POST /posts/{id}/duplicate`.
   - Backend controller duplicates the `Post` record:
     - Title: `[สำเนา] ${originalPost.title}`
     - Status: Set strictly to `'draft'`
     - `published_at` & `scheduled_at`: Set to `null`
     - Contents: `wp_content`, `fb_caption`, `fb_comment`, `featured_image_id`, `wp_category_id` cloned
     - PublishTargets: Re-created with `status: 'pending'`, `external_id: null`, `external_url: null`, `error_message: null`, `retry_count: 0`.
4. Response redirects directly to `route('posts.edit', newPost.id)` with flash success: `"ทำสำเนาโพสต์เรียบร้อยแล้ว"`.

---

### 3.6 Media Picker PDF Filtering for Facebook

#### Problem & Facebook Constraint
Facebook Graph API rejects PDF uploads with error code `100` (subcode `1349125`). Attempting to publish a PDF to Facebook causes permanent failure. While WordPress supports PDF media, Facebook strictly accepts only image types (`jpg`, `jpeg`, `png`, `webp`) or video formats.

#### Multi-Layer Enforcement Specification
1. **Dynamic Native File Input (`PostForm.jsx`)**:
   - If Facebook is selected (`data.channels.includes('facebook')`):
     - `accept="image/jpeg,image/png,image/webp"`
   - If ONLY WordPress is selected:
     - `accept="image/jpeg,image/png,image/webp,application/pdf"`
2. **Drop / Selection Interceptor**:
   - When a file is dropped or selected in the file input:
     ```javascript
     if (isFbSelected && file.type === 'application/pdf') {
         Swal.fire({
             icon: 'warning',
             title: 'Facebook ไม่รองรับไฟล์ PDF',
             text: 'ช่องทาง Facebook รองรับเฉพาะไฟล์รูปภาพ (JPG, PNG, WEBP) เท่านั้น หากต้องการแนบไฟล์ PDF กรุณาเลือกเฉพาะช่องทาง WordPress',
             confirmButtonColor: '#000080',
         });
         return;
     }
     ```
3. **Media Library Grid Filter**:
   - When browsing the existing media library in `PostForm`:
     ```javascript
     const filteredMediaList = mediaList.filter((media) => {
         if (isFbSelected) {
             return media.mime_type.startsWith('image/');
         }
         return true; // WordPress allows images and PDFs
     });
     ```
4. **Channel Checkbox Toggle Guard**:
   - If the user attaches a PDF while only WordPress is active, and then checks the **Facebook** checkbox:
     - Display a warning dialog:
       *"ตรวจพบว่าโพสต์นี้แนบไฟล์ PDF ซึ่งช่องทาง Facebook ไม่รองรับ กรุณาเปลี่ยนไฟล์สื่อเป็นรูปภาพ (JPG/PNG) หรือยกเลิกช่องทาง Facebook"*
     - Offer two buttons: `"เปลี่ยนรูปภาพ"` (clears PDF and opens image selector) or `"ยกเลิก Facebook"` (unchecks Facebook checkbox).

---

## 4. Detailed Specifications for R4 (Realtime Progress)

### 4.1 Polling Lifecycle & State Machine

```
Post Published / Retried
         │
         ▼
State: [queued / processing / retrying]
         │
         ├─── Start Polling Interval: 1500 ms (~1.5s)
         │    │
         │    ├── Polling Tick: Check Post & Target Statuses
         │    │
         │    └── Status changed?
         │         ├── Still active? ──> Continue Polling (1.5s)
         │         │
         │         └── Reached Terminal State?
         │              ├── 'published' (100% success)
         │              ├── 'failed' (All active targets failed)
         │              └── 'partially_published' (One success, one failed permanently)
         │                   │
         ▼                   ▼
Clean Termination: clearInterval(timer), unbind listeners, update UI final state
```

### 4.2 Polling Implementation Architecture

#### Dual-Tier Polling Strategy
1. **Tier 1: List View Polling (`Posts/Index.jsx`)**
   - Polls every **1500 ms** using Inertia partial reload:
     `router.reload({ only: ['posts'], preserveScroll: true, preserveState: true })`.
   - Active trigger: At least one post in `posts.data` has `status === 'publishing'` or targets with status in `['pending', 'queued', 'processing', 'retrying']`.
   - Auto-stops as soon as every post is in a terminal state (`draft`, `scheduled`, `published`, `failed`, `partially_published`).
2. **Tier 2: Dedicated JSON Status Polling (`PublishingProgressModal` & `PublishingTimelineModal`)**
   - In modals, avoids reloading the entire Inertia page state.
   - Polls a lightweight endpoint: `GET /posts/{id}/status` every **1500 ms**.
   - Directly updates React local state with smooth percentage transitions.

#### Custom Hook: `usePublishingProgress.js`
```javascript
// resources/js/Hooks/usePublishingProgress.js
import { useState, useEffect, useRef } from 'react';
import axios from 'axios';

const ACTIVE_STATUSES = ['publishing', 'queued', 'processing', 'retrying'];
const TERMINAL_STATUSES = ['published', 'failed', 'partially_published'];

export default function usePublishingProgress({ postId, initialData, active = true, onTerminalState }) {
    const [progressData, setProgressData] = useState(initialData || null);
    const [isPolling, setIsPolling] = useState(false);
    const timerRef = useRef(null);

    const isPostActive = (data) => {
        if (!data) return false;
        if (ACTIVE_STATUSES.includes(data.status)) return true;
        return data.targets?.some((t) => ACTIVE_STATUSES.includes(t.status));
    };

    useEffect(() => {
        if (!active || !postId) {
            if (timerRef.current) clearInterval(timerRef.current);
            setIsPolling(false);
            return;
        }

        const pollStatus = async () => {
            try {
                const response = await axios.get(`/posts/${postId}/status`);
                const data = response.data;
                setProgressData(data);

                // Check terminal state
                if (TERMINAL_STATUSES.includes(data.status)) {
                    // Check if all individual targets are also terminal
                    const targetsActive = data.targets?.some((t) => ACTIVE_STATUSES.includes(t.status));
                    if (!targetsActive) {
                        clearInterval(timerRef.current);
                        setIsPolling(false);
                        if (onTerminalState) onTerminalState(data);
                    }
                }
            } catch (err) {
                console.error('Polling error:', err);
                // Stop on 404 or auth failure
                if (err.response?.status === 404 || err.response?.status === 401) {
                    clearInterval(timerRef.current);
                    setIsPolling(false);
                }
            }
        };

        // Check if initial data requires polling
        if (isPostActive(progressData)) {
            setIsPolling(true);
            timerRef.current = setInterval(pollStatus, 1500); // 1.5s interval
        }

        return () => {
            if (timerRef.current) clearInterval(timerRef.current);
        };
    }, [postId, active, progressData?.status]);

    return { progressData, isPolling, setProgressData };
}
```

---

## 5. Backend Endpoints Required by Frontend

To support R2 and R4 seamlessly, the backend needs to expose the following endpoints:

| Method | Endpoint | Purpose | Request Body | Response Format |
|---|---|---|---|---|
| `GET` | `/posts/{post}/status` | Lightweight status polling for progress stepper | None | `{ id, status, progress_percentage, targets: [{ id, channel, status, external_url, error_message, retry_count }], attempts_count }` |
| `POST` | `/posts/{post}/retry/{channel}` | Trigger manual retry for a specific failed channel | None | `{ success: true, message: "เริ่มการส่งใหม่แล้ว", target: { channel, status: "queued" } }` |
| `POST` | `/posts/{post}/duplicate` | Duplicate an existing post into a fresh draft | None | Redirects to `/posts/{new_id}/edit` with flash message |
| `POST` | `/posts/autosave` | Create initial draft via autosave without navigation | `{ title, wp_content, fb_caption, fb_comment, channels, ... }` | `{ success: true, post_id: 105, slug: "...", updated_at: "..." }` |
| `PUT` | `/posts/{post}/autosave` | Update existing draft via autosave | Form fields | `{ success: true, updated_at: "..." }` |
| `GET` | `/posts/{post}/timeline` | Retrieve audit attempts and event logs | None | `{ attempts: [PublishAttempt], events: [PublishEvent] }` |

---

## 6. Implementation Phasing Guidance

### Phase 5: R2 UI & UX Refactoring
1. **Refactor `PostForm.jsx`**:
   - Extract media selection into clean subcomponents.
   - Enforce PDF filtering when Facebook is selected.
   - Integrate `useAutosave` hook with header save indicators.
   - Launch `PublishingProgressModal` on "เผยแพร่ทันที" submit.
2. **Update `Posts/Index.jsx`**:
   - Add "ทำสำเนา" (Duplicate) button with confirmation.
   - Add "ดูประวัติ" (Timeline) modal trigger.
   - Add inline "ลองใหม่" (Retry) button for failed channels.
   - Add pagination controls (`posts.links`) below the table.
3. **Build Timeline & Stepper Components**:
   - `PublishingProgressModal.jsx` & `PublishingStepper.jsx`
   - `PublishingTimelineModal.jsx`
   - `ChannelRetryButton.jsx`

### Phase 7: R4 Realtime Progress
1. Implement `GET /posts/{post}/status` backend endpoint (lightweight, minimal query).
2. Wire up `usePublishingProgress.js` with exact 1500ms intervals.
3. Verify clean cancellation when reaching `published`, `failed`, or `partially_published`.
4. Update `Posts/Index.jsx` list polling interval to 1500ms and expand target status filters to include `queued` and `retrying`.

---

## 7. Quality & Acceptance Verification Checklist

- [ ] **Agent-as-judge (R4)**: Publishing progress advances automatically in the UI without manual browser refresh (~1.5s polling).
- [ ] **Agent-as-judge (R2)**: Facebook API errors are displayed in readable Thai (e.g., "สิทธิ์ Facebook ไม่เพียงพอ", "เซสชัน Facebook หมดอายุ").
- [ ] **Agent-as-judge (R2)**: Media Picker rejects PDFs when Facebook is selected as a destination.
- [ ] **Agent-as-judge (R2)**: Manual retry per channel triggers immediate queue dispatch without duplicating successfully published channels.
- [ ] **Agent-as-judge (R2)**: Autosave updates draft state seamlessly without interrupting text typing or causing form resets.
- [ ] **Build Validation**: `npm run build` completes with 0 errors.
