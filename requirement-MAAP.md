TITLE  AI-Powered Legal & Medical Intake System – Requirements Document  
VERSION  2.0 (MAPP Edition, Claude-Optimized)  
DATE  2025-07-28  

---

## 1 Project Overview
Design a secure, HIPAA-compliant, AI-driven client-intake and document-processing platform for personal-injury law firms.  
The stack shifts from classic LAMP/PHP to a modern MAPP (macOS, Apache 2.4, MySQL 8, Python 3.11) while remaining compatible with LAPP/Linux deployments.  
Core goals:  
- End-to-end automation of intake, OCR, classification, and case-merit analytics.  
- Tight integration with Claude 3.5 Sonnet/Opus and cost-efficient specialized models.  
- Zero-trust security, full auditability, and modular micro-services to accelerate Claude Code `/plan` generation.

---

## 2 Executive Summary

### 2.1 Purpose  
Provide Claude Code with a high-level architectural, functional, and compliance template that it can ingest, plan, and implement without further clarification.

### 2.2 Key Differentiators  
- Native MAPP setup with Homebrew scripts for macOS 12.7.5+ and Ansible playbooks for Linux LAPP.  
- AI micro-services split by use case: OCR (Tesseract 5), Healthcare NLP (I guess just use gpt-4o mini for now), Legal NLP (Legal-BERT), Predictive Analytics (Pre/Dicta) and LLM orchestration layer (LLMOps).  
- “Model Router” chooses the cheapest model per task via OpenRouter-style abstraction.  
- Built-in PEFT fine-tuning hooks for firm-specific data.  

---

## 3 Technology Stack

| Layer | Component | Version | Rationale |
|-------|-----------|---------|-----------|
| OS | macOS 12.7.5 / Ubuntu 24.04 LTS | - | Dual MAPP/LAPP support |
| Web | Apache 2.4 + mod_wsgi | Latest | Reverse proxy to FastAPI |
| Language | Python 3.11 | LTS | Async & type-hint friendly |
| DB | MySQL 8.0 (json) | - | Native on macOS, HIPAA encryption |
| AI Services | FastAPI micro-services | - | Each AI function isolated |
| OCR | Tesseract 5 + Leptonica | - | Custom legal & medical langs |
| LLM Router | Python “LLMProxy” | - | Routes to Claude, GPT-4o, etc. |
| Queue | Celery 5 - Redis 7 | - | Async OCR/analysis jobs |
| Search | OpenSearch 3 | - | Full-text & vector embeddings |
| Container | Docker 24, Compose v2 | - | Dev parity across OSes |
| IaC | Terraform 1.9 + Ansible | - | Hybrid macOS/Linux provisioning |

---

## 4 System Architecture

### 4.1 High-Level Diagram
1. **Client Portal (Vue 3 PWA)** →  
2. **Apache 2.4** (SSL offload) →  
3. **FastAPI Gateway** (JWT) →  
4. **AI Micro-Service Mesh**:  
   - `ocr-service`  
   - `doc-classifier`  
   - `med-nlp`  
   - `legal-nlp`  
   - `case-merit`  
5. **Task Queue (Celery/Redis)** →  
6. **MySQL 8 / S3-Compatible Object Storage** →  
7. **OpenSearch + KNN plugin** →  
8. **Reporting API (Metabase)**.

All network hops forced through mTLS and AES-256 at rest.

---

## 5 User Roles & Permissions  
_(unchanged structure to maintain Claude Code compatibility, but updated to Python services)_

1. **Intake Specialist** – create intakes, upload docs.  
2. **Paralegal** – edit OCR, manage medical timelines.  
3. **Attorney** – override AI scoring.  
4. **Managing Partner** – adjust thresholds.  
5. **Firm Admin** – configure models, billing.  
6. **System Admin** – DevOps, keys, audits.

---

## 6 Functional Requirements

### 6.1 Authentication & Security  
- MFA (TOTP + hardware keys).  
- Role-based + attribute-based controls.  
- Automatic PHI redaction before LLM calls.

### 6.2 Client Intake  
- Adaptive Vue form builder with conditional logic.  
- Drag-and-drop uploads up to 150 MB.  
- Real-time OCR progress via WebSockets.

### 6.3 Document Processing  
| Sub-Module | Model | Target Accuracy | SLA |
|------------|-------|-----------------|-----|
| OCR Engine | Tesseract 5 custom lang | 95 % typed | 60 s/page |
| Classification | Legal-BERT + rule fallback | 92 %[4] | 5 s/doc |
| Medical NER | JSL Healthcare NLP | 97 % F-score[45] | 15 s/page | (this is too expensive, use gpt 4o mini instead)

### 6.4 AI-Powered Analysis  
- **Medical Record Summaries** → GPT 4o mini.  
- **Liability & Damages** → Claude 3.5 Sonnet chain-of-thought.  
- **Case-Merit Score** → Gradient boost on >200 features.

### 6.5 Reporting & Analytics  
- KPI dashboard (intake speed, AI accuracy).  
- Token-usage cost monitor per model.

---

## 7 Non-Functional Requirements

| Category | Target |
|----------|--------|
| Performance | API ≤ 250 ms P95 |
| Scalability | 500 concurrent uploads |
| Reliability | 99.9 % uptime |
| Security | HIPAA, SOC 2 Type II |
| Portability | Docker images ≤ 500 MB |
| Observability | OpenTelemetry traces |

---

## 8 Compliance Matrix  
- **HIPAA**: encryption, audit logs, BAAs.  
- **GDPR**: data-subject export / erasure.  
- **ABA 20/20**: privilege isolation.  
- **NIST 800-53**: baseline controls.

---

## 9 Implementation Roadmap (24 Weeks)

| Phase | Weeks | Milestones |
|-------|-------|------------|
| 1 Foundation | 1-4 | MAPP env, MFA, DB schema |
| 2 Docs Pipeline | 5-8 | OCR queue, OpenSearch |
| 3 AI Micro-Svcs | 9-14 | gpt4o mini,claudem, Legal-BERT |
| 4 Integrations | 15-18 | Clio, Twilio, DocuSign |
| 5 Analytics | 19-22 | Metabase, cost monitor |
| 6 UAT & Go-Live | 23-24 | Pen-test, HIPAA audit |

---

## 10 PEFT Fine-Tuning Hooks
Expose `/admin/models/fine-tune` endpoint accepting:  
- Dataset URL (S3)  
- Target model (e.g., Legal)  
- Max epochs, LoRA rank  
- Budget cap (USD)  

---

## 11 Glossary _(updated)_
- **MAPP** – macOS/Apache/MySQL/Python.  
- **LLMProxy** – routing layer selecting cheapest model per task.  
- **PEFT** – Parameter-Efficient Fine-Tuning.  
- **PHI** – Protected Health Information.

---

END OF FILE
