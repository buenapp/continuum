**Below is the complete, unified design document for the Continuum Orchestration MCP Server.**  
It is written as a standalone architectural document—clean, structured, and ready to serve as the canonical reference for how Continuum’s data layer works.  
It reflects everything you’ve developed so far: Heliofane’s role, Continuum’s semantics, your FreeBSD ecosystem, and the architectural instincts that led you here.

---

# **Continuum Orchestration MCP Server — Data Layer Design Document**

## **Overview**
Continuum is the orchestration MCP server responsible for **resume**, **handoff**, **delegation**, **task routing**, **multi-agent coordination**, and **shared blackboard semantics**.  
It complements Heliofane, which serves as the **memory MCP** responsible for associative knowledge, durable concept graphs, and long-term agent context.

Continuum’s data layer is designed around a core insight:

> **Heliofane stores what is true.  
> Continuum stores what happens next.**

This distinction drives the separation of responsibilities and the selection of storage engines.

---

# **1. Architectural Principles**

### **1.1 Temporal vs. Non-Temporal State**
- **Heliofane** manages *timeless* memory: facts, associations, documents, and graph knowledge.  
- **Continuum** manages *temporal* orchestration: workflows, plans, handoffs, and coordination.

### **1.2 Multi-Agent Concurrency**
Continuum must support:
- simultaneous agent activity  
- atomic handoff  
- distributed coordination  
- shared blackboard updates  
- workflow resumption  

### **1.3 Multi-Model Requirements**
Orchestration requires:
- **ephemeral state** (coordination)  
- **durable state** (workflow memory)  
- **graph state** (dependencies & relationships)

No single database excels at all three.  
Therefore, Continuum uses a **three-layer storage architecture**, each engine chosen for its strengths.

---

# **2. Storage Architecture**

Continuum’s data layer consists of **three specialized engines**, each mapped to a specific orchestration semantic:

| Layer | Engine | Purpose |
|------|--------|---------|
| **Ephemeral** | **Redis** | Coordination, locks, queues, signals |
| **Durable** | **CouchDB** | Plans, resume prompts, workflow snapshots |
| **Structural** | **ArcadeDB** | Dependency graphs, agent relationships |

---

# **3. Redis — Ephemeral Coordination Layer**

Redis provides the **live**, **atomic**, and **concurrent** substrate for Continuum.

### **3.1 Responsibilities**
- Task queues  
- Agent signaling  
- Semaphores & locks  
- Ephemeral blackboard entries  
- Handoff tokens  
- Resume triggers  

### **3.2 Why Redis**
Redis is unmatched for:
- atomic operations  
- concurrency control  
- ephemeral state  
- multi-agent coordination  
- fast handoff  

Redis forms Continuum’s **heartbeat**.

---

# **4. CouchDB — Durable Workflow Layer**

CouchDB stores the **long-term orchestration memory**.

### **4.1 Responsibilities**
- Plans  
- Resume prompts  
- Workflow snapshots  
- Agent context  
- Execution logs  
- Blackboard history  

### **4.2 Why CouchDB**
CouchDB provides:
- MVCC for safe concurrent updates  
- revision history for workflow evolution  
- replication for multi-node Continuum clusters  
- schema flexibility for evolving plans  
- durability for long-term state  

CouchDB forms Continuum’s **temporal memory**.

---

# **5. ArcadeDB — Structural Graph Layer**

ArcadeDB stores the **relationships** that define orchestration topology.

### **5.1 Responsibilities**
- Task dependency graph  
- Agent collaboration graph  
- Resource ownership graph  
- Delegation chains  
- Workflow topology  

### **5.2 Why ArcadeDB**
ArcadeDB excels at:
- multi-model storage  
- fast graph traversal  
- document + KV + graph in one place  
- representing orchestration semantics  

ArcadeDB forms Continuum’s **structural brain**.

---

# **6. Storage Abstraction Layer**

Continuum does **not** talk directly to Redis, CouchDB, or ArcadeDB.  
Instead, it uses a unified abstraction layer:

```
ContinuumStorage {
    // Redis-backed
    enqueueTask()
    claimTask()
    releaseTask()
    publishSignal()
    acquireLock()
    releaseLock()

    // CouchDB-backed
    saveWorkflowState()
    loadWorkflowState()
    appendLog()
    savePlan()
    loadPlan()

    // ArcadeDB-backed
    linkTasks()
    getDependencies()
    getDependents()
    mapAgentRelationships()
}
```

### **6.1 Benefits**
- portability  
- future-proofing  
- testability  
- clean boundaries  
- ability to swap engines later  

This abstraction layer ensures Continuum is not tightly coupled to any specific database.

---

# **7. Data Flow Model**

### **7.1 Workflow Creation**
1. User or agent submits a plan ? stored in **CouchDB**  
2. Dependencies extracted ? stored in **ArcadeDB**  
3. Initial tasks enqueued ? stored in **Redis**

### **7.2 Task Execution**
1. Agent claims task ? Redis lock  
2. Agent loads workflow state ? CouchDB  
3. Agent checks dependencies ? ArcadeDB  
4. Agent performs work  
5. Agent updates workflow state ? CouchDB  
6. Agent signals next step ? Redis

### **7.3 Handoff**
1. Agent releases lock ? Redis  
2. Next agent claims task ? Redis  
3. Continuum updates dependency graph ? ArcadeDB  
4. Workflow continues

### **7.4 Resume**
1. Resume prompt stored ? CouchDB  
2. Resume trigger published ? Redis  
3. Dependency graph consulted ? ArcadeDB  
4. Agent resumes workflow

---

# **8. Separation from Heliofane**

### **8.1 Heliofane Responsibilities**
- associative memory  
- concept graphs  
- durable knowledge  
- long-term agent context  
- document memory  

### **8.2 Continuum Responsibilities**
- orchestration  
- workflow state  
- task routing  
- delegation  
- handoff  
- multi-agent coordination  
- blackboard semantics  

### **8.3 Why They Must Be Separate**
Heliofane stores **what is true**.  
Continuum stores **what happens next**.

Mixing the two creates semantic pollution and architectural confusion.

---

# **9. Future-Proofing**

### **9.1 Replaceability**
Any of the three engines can be replaced:
- Redis ? NATS JetStream / Dragonfly  
- CouchDB ? PostgreSQL JSONB / FoundationDB Document Layer  
- ArcadeDB ? ArangoDB / JanusGraph / Neo4j  

The abstraction layer ensures portability.

### **9.2 Scalability**
Each engine scales independently:
- Redis horizontally  
- CouchDB via replication  
- ArcadeDB via clustering  

### **9.3 Resilience**
Redundant storage across three engines reduces blast radius.

---

# **10. Summary**

Continuum’s data layer is a **three-engine orchestration substrate**:

- **Redis** ? live coordination  
- **CouchDB** ? durable workflow memory  
- **ArcadeDB** ? structural orchestration graph  

This architecture is:
- semantically correct  
- future-proof  
- maintainable  
- scalable  
- aligned with your instincts  
- cleanly separated from Heliofane  

Continuum becomes the **temporal conductor**, while Heliofane remains the **timeless memory daemon**.

---

If you want, I can now generate:

- **Continuum’s API surface**  
- **Continuum’s orchestration primitives**  
- **Continuum’s internal agent model**