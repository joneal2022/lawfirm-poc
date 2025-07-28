# AI-Powered Legal & Medical Intake System – Requirements Document  
**VERSION**  2.1 (MAPP Edition,POC-Optimized Edition)  
**DATE**  2025-07-28  

---

## 1 Project Overview
Design a secure, HIPAA-compliant, AI-driven client-intake and document-processing platform for personal-injury law firms.  
The stack shifts from classic LAMP/PHP to a modern MAPP (macOS, Apache 2.4, MySQL 8, Python 3.11) while remaining compatible with LAPP/Linux deployments.  
**POC Focus**: Rapid deployment using GPT-4o mini and Claude 3.5 Sonnet for immediate validation, with fine-tuning roadmap for production scaling.

Core goals:  
- End-to-end automation of intake, OCR, classification, and case-merit analytics  
- Cost-efficient AI routing between GPT-4o mini (bulk operations) and Claude 3.5 Sonnet (complex reasoning)  
- Zero-trust security, full auditability, and modular micro-services to accelerate Claude Code `/plan` generation  

---

## 2 Executive Summary

### 2.1 Purpose  
Provide Claude Code with a high-level architectural, functional, and compliance template optimized for rapid POC deployment using commercially available LLM APIs.

### 2.2 Key Differentiators  
- Native MAPP setup with Homebrew scripts for macOS 12.7.5+ and Ansible playbooks for Linux LAPP  
- **AI micro-services optimized for POC**: OCR (Tesseract 5), Medical NLP (GPT-4o mini), Legal NLP (Claude 3.5 Sonnet), Document Classification (GPT-4o mini), Case Merit Analysis (Claude 3.5 Sonnet)  
- **Smart Model Router** selects GPT-4o mini for cost-efficiency or Claude 3.5 Sonnet for complex reasoning  
- Built-in PEFT fine-tuning hooks for future Legal-BERT and MedGemma integration  

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
| **Primary LLMs** | **GPT-4o mini + Claude 3.5 Sonnet** | **API** | **POC-optimized cost/performance** |
| LLM Router | Python "LLMProxy" | - | Routes based on task complexity |
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
   - `ocr-service` (Tesseract 5)  
   - `doc-classifier` (GPT-4o mini)  
   - `med-nlp` (GPT-4o mini)  
   - `legal-nlp` (Claude 3.5 Sonnet)  
   - `case-merit` (Claude 3.5 Sonnet)  
5. **Task Queue (Celery/Redis)** →  
6. **MySQL 8 / S3-Compatible Object Storage** →  
7. **OpenSearch + KNN plugin** →  
8. **Reporting API (Metabase)**  

All network hops forced through mTLS and AES-256 at rest.

---

## 5 User Roles & Permissions  
_(unchanged structure to maintain Claude Code compatibility)_

1. **Intake Specialist** – create intakes, upload docs  
2. **Paralegal** – edit OCR, manage medical timelines  
3. **Attorney** – override AI scoring  
4. **Managing Partner** – adjust thresholds  
5. **Firm Admin** – configure models, billing  
6. **System Admin** – DevOps, keys, audits  

---

## 6 Functional Requirements

### 6.1 Authentication & Security  
- MFA (TOTP + hardware keys)  
- Role-based + attribute-based controls  
- **Automatic PHI redaction** before LLM API calls (critical for HIPAA)  

### 6.2 Client Intake  
- Adaptive Vue form builder with conditional logic  
- Drag-and-drop uploads up to 150 MB  
- Real-time OCR progress via WebSockets  

### 6.3 Document Processing  
| Sub-Module | Model | Target Accuracy | SLA | Cost (per 1M tokens) |
|------------|-------|-----------------|-----|---------------------|
| OCR Engine | Tesseract 5 custom | 95% typed | 60s/page | N/A |
| **Medical NLP** | **GPT-4o mini** | **88-90%** | **15s/page** | **$0.15 input/$0.60 output** |
| **Legal Classification** | **Claude 3.5 Sonnet** | **92%** | **5s/doc** | **$3 input/$15 output** |
| Document Classification | GPT-4o mini | 87% | 3s/doc | $0.15 input/$0.60 output |

### 6.4 AI-Powered Analysis  
- **Medical Record Summaries** → GPT-4o mini (cost-efficient, 88% accuracy)  
- **Legal Document Classification** → GPT-4o mini (bulk processing)  
- **Liability & Damages Analysis** → Claude 3.5 Sonnet (complex reasoning)  
- **Case-Merit Score** → Claude 3.5 Sonnet chain-of-thought + gradient boost features  

### 6.5 Model Routing Logic
- **def route_llm_request(task_type, document_length, complexity_score):
        if task_type == "case_merit" or complexity_score > 0.8:
            return "claude-3.5-sonnet"
        elif document_length > 100000 or task_type == "legal_reasoning":
            return "claude-3.5-sonnet" # Large context window
        else:
            return "gpt-4o-mini" # Cost optimization

### 6.6 Reporting & Analytics  
- KPI dashboard (intake speed, AI accuracy)  
- **Real-time token usage cost monitor** per model  
- Monthly cost projection and optimization recommendations  

---

## 7 Non-Functional Requirements

| Category | Target |
|----------|--------|
| Performance | API ≤ 250 ms P95 |
| Scalability | 500 concurrent uploads |
| Reliability | 99.9% uptime |
| Security | HIPAA, SOC 2 Type II |
| **Cost Efficiency** | **<$0.006/doc blended AI spend** |
| Portability | Docker images ≤ 500 MB |
| Observability | OpenTelemetry traces |

---

## 8 Compliance Matrix  
- **HIPAA**: encryption, audit logs, BAAs, **PHI redaction before API calls**  
- **GDPR**: data-subject export / erasure  
- **ABA 20/20**: privilege isolation  
- **NIST 800-53**: baseline controls  

---

## 9 Implementation Roadmap (16 Weeks - POC Accelerated)

| Phase | Weeks | Milestones |
|-------|-------|------------|
| 1 Foundation | 1-2 | MAPP env, MFA, DB schema |
| 2 Docs Pipeline | 3-4 | OCR queue, OpenSearch |
| 3 **AI Integration** | **5-8** | **GPT-4o mini + Claude APIs, routing logic** |
| 4 POC Validation | 9-10 | User testing, accuracy benchmarks |
| 5 Integrations | 11-13 | Clio, Twilio, DocuSign |
| 6 Production Prep | 14-16 | Load testing, HIPAA audit prep |

---

## 10 Future Fine-Tuning Roadmap
**Phase 2 (Post-POC)**: Expose `/admin/models/fine-tune` endpoint accepting:  
- Dataset URL (S3)  
- Target model options: Legal-BERT, MedGemma, custom LoRA  
- Max epochs, LoRA rank  
- Budget cap (USD)  
- **Cost-benefit threshold**: Switch to fine-tuned models when volume >5k docs/month  

---

## 11 Cost Optimization Strategy
### 11.1 Token Management
- **Max tokens limit**: 1,200 per request (reduces costs 30%)  
- **Prompt compression**: Use terse system prompts  
- **Batch processing**: Group similar documents for efficiency  

### 11.2 Model Selection Thresholds
- **Simple classification**: GPT-4o mini only  
- **Medical summarization**: GPT-4o mini (88% accuracy sufficient)  
- **Complex legal reasoning**: Claude 3.5 Sonnet (92% accuracy required)  
- **Large documents (>50k tokens)**: Claude 3.5 Sonnet (200k context)  

---

## 12 Glossary _(updated)_
- **MAPP** – macOS/Apache/MySQL/Python  
- **LLMProxy** – routing layer selecting cheapest model per task complexity  
- **PEFT** – Parameter-Efficient Fine-Tuning (future integration)  
- **PHI** – Protected Health Information  
- **POC** – Proof of Concept (current phase focus)  

---

END OF FILE
