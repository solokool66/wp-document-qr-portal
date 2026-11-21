# 🔲 Dynamic Document & Link QR Portal for WordPress

<p align="left">
  <img src="https://img.shields.io/badge/PHP-8.0%20%7C%208.1%20%7C%208.2%20%7C%208.3-777BB4?style=for-the-badge&logo=php&logoColor=white" />
  <img src="https://img.shields.io/badge/WordPress-6.0%2B-21759B?style=for-the-badge&logo=wordpress&logoColor=white" />
  <img src="https://img.shields.io/badge/Frontend-Canvas%20%2F%20SVG-F7DF1E?style=for-the-badge&logo=javascript&logoColor=black" />
  <img src="https://img.shields.io/badge/Database-MySQL%20%2F%20MariaDB-4479A1?style=for-the-badge&logo=mysql&logoColor=white" />
  <img src="https://img.shields.io/badge/License-GPL%20v2-blue?style=for-the-badge" />
</p>

A high-performance WordPress plugin combining interactive client-side vector QR code generation (Canvas & SVG) with custom URL shortlink routing, secure document hosting, and real-time download telemetry. Designed for organizations, conferences, and businesses that need branded, trackable QR codes and shortlinks with zero external API dependencies.

> 💡 **Community Edition (Developer Preview):** Features the full interactive vector styling studio and shortlink redirection engine, capped at **10 active QR codes / documents**. For unlimited shortlinks, bulk CSV import, and white-label custom domain mapping, see the [Pro Edition Roadmap](#-community-vs-pro-edition).

---

## 🏗️ System Architecture

```mermaid
flowchart TD
    A["Admin / Frontend Portal\n(Shortcode & Upload Panel)"] --> B{"Input Mode"}
    
    B -->|"Document File"| C["File Storage Engine\n(wp_upload_dir & MIME Whitelist)"]
    B -->|"Web Link URL"| D["External URL Router\n(Escaped HTTP Target)"]
    B -->|"Plain Text Note"| E["Text Content Store\n(Announcements & Notes)"]
    
    C --> F["Custom Slug Sanitizer\n(/qr/{slug} Validation)"]
    D --> F
    E --> F
    
    F --> G["Custom dbDelta Table\n(wp_tka_qr_documents)"]
    
    G --> H["Client-Side Vector Engine\n(qr-code-styling.js / Canvas & SVG)"]
    H --> I["Branded QR Code\n(Logo Embedding, Gradients, Shapes)"]
    
    J["Visitor Scans QR Code"] --> K["WordPress Rewrite Engine\n(Template Redirect /qr/)"]
    K --> L["Download Telemetry Increment\n(Atomic $wpdb Counter)"]
    L --> M["Direct Download or Landing Page"]
```

---

## ✨ Key Engineering Features

### 1. Interactive Client-Side Vector Styling (`qr-code-styling.js`)
- **Real-Time Canvas & SVG Rendering:** Renders vector QR codes dynamically in the browser without server CPU or GD image generation bottlenecks.
- **Custom Aesthetic Controls:**
  - Dot patterns (Dots, Rounded, Classy, Square).
  - Corner square & corner dot custom shapes.
  - Linear and radial color gradients.
  - Centered brand logo watermarking with automatic error-correction level adjustment (`Level H`).

### 2. High-Speed URL Shortlink Routing
- Implements custom WordPress rewrite rules (`add_rewrite_rule`) to map clean `/qr/{slug}` requests directly without creating dummy WordPress posts or pages.
- Intercepts resolution at `template_redirect` for sub-millisecond redirect and download delivery.

### 3. Secure Multi-Type Document Hosting
Supports three distinct payload categories:
1. **Document Files:** PDF, DOCX, PPTX, XLSX, and images stored securely in WordPress uploads.
2. **Dynamic Web Links:** Outbound URL shortlinks with live destination editing.
3. **Plain Text / Announcements:** Instant mobile-friendly reading pages for memos and notices.

### 4. Enterprise Security & Sanitization
- **Nonced Endpoints:** All generation and deletion calls are protected by `wp_create_nonce` and `check_ajax_referer`.
- **Slug Sanitization:** Enforces strict alphanumeric/hyphen filtering with unique database index collision detection.
- **Security PIN Gate:** Optional PIN lock to protect public or shared upload portals from unauthorized uploads.

---

## 📊 Community vs. Pro Edition

| Feature | ⚡ Community Edition (This Repo) | 🚀 Pro Enterprise Edition |
| :--- | :--- | :--- |
| **Active Shortlinks & QR Codes** | Up to 10 active items | **Unlimited** |
| **Live Vector Customizer** | Full Access (Canvas & PNG export) | **Full Access + 300 DPI Print SVG / PDF** |
| **Shortlink Routing** | Native `/qr/{slug}` | **Custom Multi-Domain White-Labeling** |
| **Download Analytics** | Aggregate Download Counter | **Visitor Device, OS, Browser & Geo-Telemetry** |
| **Batch Generation** | Single creation | **Bulk CSV Import & Multi-QR Export (.zip)** |
| **Password-Protected Links** | Not included | **PIN / Password Gate per Link** |

---

## 🚀 Installation & Setup

1. **Clone or Download:**
   ```bash
   git clone https://github.com/solokool66/wp-document-qr-portal.git
   ```
2. **Deploy to WordPress:**
   Copy the `wp-document-qr-portal` folder into:
   ```text
   /wp-content/plugins/wp-document-qr-portal/
   ```
3. **Activate:**
   Go to your WordPress Admin Dashboard -> **Plugins** -> Activate **Dynamic Document & Link QR Portal**.
4. **Flush Permalinks (One-Time):**
   Navigate to **Settings -> Permalinks** and click **Save Changes** to register the `/qr/` rewrite rule.
5. **Frontend Display:**
   Use the shortcode `[tka_document_qr_portal]` on any page to display the user-facing upload and QR generator interface!

---

## ❓ Frequently Asked Questions (FAQ)

#### Q1: Does this require an external QR code API service?
> **No.** All QR codes are rendered 100% locally in the user's browser using client-side Canvas and SVG vector math. Zero external tracking, zero API keys, and zero subscription costs.

#### Q2: Can I change the destination of a QR code after it has been printed?
> **Yes.** Because the QR code points to your dynamic shortlink (`/qr/myslug`), you can update the destination file or URL at any time in your dashboard without reprinting the QR code.

#### Q3: How are file downloads tracked?
> Each access through the `/qr/` route triggers an atomic increment on the database index (`download_count`), giving you exact delivery totals for distributed documents.

---

## 👤 Author & Architecture

**Solomon, Kingsley Eze**  
*Software & Network Systems Engineer*  
- 💼 **LinkedIn:** [linkedin.com/in/solokool](https://www.linkedin.com/in/solokool/)  
- 🐙 **GitHub:** [@solokool66](https://github.com/solokool66)  
- 📧 **Email:** [solokool66@gmail.com](mailto:solokool66@gmail.com)  

---

## 📄 License
Licensed under the [GNU General Public License v2.0 or later](LICENSE).
