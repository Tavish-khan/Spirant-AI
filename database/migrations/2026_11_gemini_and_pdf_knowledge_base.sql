USE spirant_ai;

CREATE TABLE IF NOT EXISTS conversation_ai_state (
    conversation_id INT NOT NULL PRIMARY KEY,
    user_id INT NOT NULL,
    provider VARCHAR(40) NOT NULL DEFAULT 'gemini',
    provider_interaction_id VARCHAR(255) NOT NULL,
    model VARCHAR(120) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_conversation_ai_state_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversation_ai_state_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_conversation_ai_state_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS knowledge_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uploaded_by INT NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(80) NOT NULL DEFAULT 'application/pdf',
    file_size INT NOT NULL,
    status ENUM('uploaded', 'extracting', 'indexed', 'failed') NOT NULL DEFAULT 'uploaded',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_knowledge_documents_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_knowledge_documents_stored_filename (stored_filename),
    INDEX idx_knowledge_documents_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS knowledge_document_pages (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    page_number INT NOT NULL,
    extracted_text LONGTEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_knowledge_pages_document FOREIGN KEY (document_id) REFERENCES knowledge_documents(id) ON DELETE CASCADE,
    UNIQUE KEY uq_knowledge_document_page (document_id, page_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS knowledge_document_chunks (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    page_number INT NOT NULL,
    chunk_number INT NOT NULL,
    chunk_text LONGTEXT NOT NULL,
    embedding_json LONGTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_knowledge_chunks_document FOREIGN KEY (document_id) REFERENCES knowledge_documents(id) ON DELETE CASCADE,
    UNIQUE KEY uq_knowledge_document_chunk (document_id, page_number, chunk_number),
    INDEX idx_knowledge_chunks_document_page (document_id, page_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
