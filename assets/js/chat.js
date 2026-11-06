const resolveImageUrl = (path) => {
    if (!path) return '';
    if (/^https?:\/\//i.test(path)) return path;

    let normalized = String(path).trim();
    normalized = normalized.replace(/\\/g, '/');
    normalized = normalized.replace(/\/api(?=\/uploads\/)/i, '');
    normalized = normalized.startsWith('/') ? normalized : `/${normalized.replace(/^\.?\//, '')}`;

    try {
        return new URL(normalized, window.location.origin).href;
    } catch (error) {
        return normalized;
    }
};

const bindImageFallbacks = (rootNode) => {
    if (!rootNode) return;

    rootNode.querySelectorAll('.message-image').forEach((img) => {
        img.addEventListener('error', () => {
            const fallback = document.createElement('div');
            fallback.className = 'message-image-fallback';
            fallback.innerHTML = '<i class="fa-solid fa-image"></i><span>Image unavailable</span>';
            img.replaceWith(fallback);
        });
    });
};

document.addEventListener('DOMContentLoaded', () => {
    const state = {
        currentConversationId: '',
        conversations: [],
        newConversationIds: new Set(),
        messageCounts: new Map(),
        selectedImage: null,
        isSending: false,
        shouldFollowLatest: true,
        previousScrollTop: 0,
        isRenderingMessages: false,
    };

    const welcomeCard = document.getElementById('welcomeCard');
    const chatPage = document.querySelector('.chat-page');
    const chatMessages = document.getElementById('chatMessages');
    const conversationGroups = document.getElementById('conversationGroups');
    const generateButton = document.getElementById('newChatBtn');
    const messageInput = document.getElementById('messageInput');
    const sendBtn = document.getElementById('sendBtn');
    const form = document.getElementById('chatForm');
    const imageInput = document.getElementById('imageInput');
    const selectedImageBox = document.getElementById('selectedImageBox');
    const selectedImagePreview = document.getElementById('selectedImagePreview');
    const removeImageBtn = document.getElementById('removeImageBtn');
    const conversationIdField = document.getElementById('conversationId');
    const conversationSearch = document.getElementById('conversationSearch');
    const chatError = document.getElementById('chatError');
    const chatErrorText = document.getElementById('chatErrorText');
    const dismissChatError = document.getElementById('dismissChatError');
    const activeConversationStorageKey = 'spirant-active-conversation';

    const chatErrorMessages = {
        temporary_server: "I'm having trouble responding right now. Please try again in a moment.",
        network: 'Connection issue. Please check your internet connection and try again.',
        configuration: 'AI service is temporarily unavailable. Please contact the administrator.',
        rate_limit: 'Too many requests right now. Please wait a moment and try again.',
        unsupported_image: 'This image format is not supported. Please upload a JPG, PNG, or WEBP image.',
        image_too_large: 'This image is too large. Please upload a smaller image.',
        general: 'Something went wrong. Please try again.',
    };

    const clearChatError = () => {
        if (chatError) chatError.classList.add('hidden');
        if (chatErrorText) chatErrorText.textContent = '';
    };

    const showChatError = (type) => {
        if (!chatError || !chatErrorText) return;
        chatErrorText.textContent = chatErrorMessages[type] || chatErrorMessages.general;
        chatError.classList.remove('hidden');
    };

    const rememberConversation = (id) => {
        if (id) {
            sessionStorage.setItem(activeConversationStorageKey, String(id));
        } else {
            sessionStorage.removeItem(activeConversationStorageKey);
        }
    };

    chatPage.addEventListener('scroll', () => {
        const distanceFromBottom = chatPage.scrollHeight - chatPage.clientHeight - chatPage.scrollTop;
        if (!state.isRenderingMessages) {
            if (chatPage.scrollTop < state.previousScrollTop && distanceFromBottom > 80) {
                state.shouldFollowLatest = false;
            } else if (distanceFromBottom <= 80) {
                state.shouldFollowLatest = true;
            }
        }
        state.previousScrollTop = chatPage.scrollTop;
    }, { passive: true });

    const setSendState = (disabled) => {
        if (sendBtn) sendBtn.disabled = disabled;
    };

    const toggleWelcomeCard = (show) => {
        if (welcomeCard) {
            welcomeCard.style.display = show ? 'block' : 'none';
        }
    };

    const autoResize = () => {
        if (!messageInput) return;
        messageInput.style.height = 'auto';
        messageInput.style.height = `${Math.min(messageInput.scrollHeight, 180)}px`;
    };

    const scrollToLatest = (behavior = 'smooth') => {
        if (!chatPage || !state.shouldFollowLatest) return;
        chatPage.scrollTo({ top: chatPage.scrollHeight, behavior });
    };

    const resetChatScroll = () => {
        const previousBehavior = chatPage.style.scrollBehavior;
        chatPage.style.scrollBehavior = 'auto';
        chatPage.scrollTop = 0;
        state.previousScrollTop = 0;
        state.shouldFollowLatest = false;
        chatMessages.classList.add('new-conversation');
        requestAnimationFrame(() => {
            chatPage.style.scrollBehavior = previousBehavior;
        });
    };

    const waitForImages = async (rootNode) => {
        const images = [...rootNode.querySelectorAll('.message-image')];
        await Promise.all(images.map((image) => {
            if (image.complete) {
                return typeof image.decode === 'function' ? image.decode().catch(() => {}) : Promise.resolve();
            }
            return new Promise((resolve) => {
                image.addEventListener('load', resolve, { once: true });
                image.addEventListener('error', resolve, { once: true });
            });
        }));
    };

    const scrollExistingConversationToLatest = async () => {
        await waitForImages(chatMessages);
        requestAnimationFrame(() => {
            requestAnimationFrame(() => scrollToLatest());
        });
    };

    const renderConversations = (list) => {
        if (!conversationGroups) return;

        const groups = {
            Today: [],
            Yesterday: [],
            'Last 7 Days': [],
            'Last 30 Days': [],
            Older: [],
        };

        const now = new Date();

        list.forEach((conversation) => {
            const updated = new Date(conversation.updated_at || conversation.created_at);
            const diffDays = Math.floor((now - updated) / (1000 * 60 * 60 * 24));

            if (diffDays === 0) groups.Today.push(conversation);
            else if (diffDays === 1) groups.Yesterday.push(conversation);
            else if (diffDays <= 7) groups['Last 7 Days'].push(conversation);
            else if (diffDays <= 30) groups['Last 30 Days'].push(conversation);
            else groups.Older.push(conversation);
        });

        conversationGroups.innerHTML = Object.entries(groups).map(([label, items]) => {
            if (!items.length) return '';
            return `
                <div class="group-block">
                    <div class="group-label">${label}</div>
                    ${items.map((conversation) => `
                        <div class="conversation-item ${String(state.currentConversationId) === String(conversation.id) ? 'active' : ''}" data-id="${conversation.id}">
                            <div class="conversation-summary">
                                <strong>${escapeHtml(conversation.title || 'Untitled conversation')}</strong>
                                <span>${formatDateTime(conversation.updated_at)}</span>
                            </div>
                            <div class="conversation-actions">
                                <button type="button" class="rename-conversation" data-id="${conversation.id}" title="Rename">
                                    <i class="fa-solid fa-pen"></i>
                                </button>
                                <button type="button" class="delete-conversation" data-id="${conversation.id}" title="Delete">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    `).join('')}
                </div>
            `;
        }).join('');

        conversationGroups.querySelectorAll('.conversation-item').forEach((item) => {
            item.addEventListener('click', (event) => {
                if (event.target.closest('.rename-conversation') || event.target.closest('.delete-conversation')) {
                    return;
                }
                const id = item.dataset.id;
                state.currentConversationId = id;
                loadExistingConversation(id);
                renderConversations(state.conversations);
            });
        });

        conversationGroups.querySelectorAll('.rename-conversation').forEach((button) => {
            button.addEventListener('click', () => {
                const id = button.dataset.id;
                const currentText = state.conversations.find((item) => String(item.id) === String(id))?.title || 'Untitled conversation';
                const nextTitle = window.prompt('Rename conversation', currentText);
                if (nextTitle && nextTitle.trim()) {
                    renameConversation(id, nextTitle.trim());
                }
            });
        });

        conversationGroups.querySelectorAll('.delete-conversation').forEach((button) => {
            button.addEventListener('click', () => {
                const id = button.dataset.id;
                if (window.confirm('Delete this conversation?')) {
                    deleteConversation(id);
                }
            });
        });
    };

    const loadConversations = async (search = '') => {
        const url = search ? `api/conversations.php?search=${encodeURIComponent(search)}` : 'api/conversations.php';
        const response = await fetch(url, { credentials: 'same-origin' });
        const data = await response.json();
        if (data.success) {
            state.conversations = data.data.conversations || [];
            const shouldLoadLatest = !state.currentConversationId && state.conversations.length > 0;
            if (shouldLoadLatest) {
                const rememberedId = sessionStorage.getItem(activeConversationStorageKey);
                const rememberedConversation = state.conversations.find((conversation) => String(conversation.id) === rememberedId);
                state.currentConversationId = String(rememberedConversation?.id || state.conversations[0].id);
                rememberConversation(state.currentConversationId);
            }
            renderConversations(state.conversations);
            if (shouldLoadLatest) {
                loadExistingConversation(state.currentConversationId);
            }
        }
    };

    const loadMessages = async (id, mode = 'existing') => {
        if (conversationIdField) conversationIdField.value = id || '';
        rememberConversation(id);

        if (!id) {
            state.isRenderingMessages = false;
            chatMessages.innerHTML = '';
            toggleWelcomeCard(true);
            return;
        }

        const conversationKey = String(id);
        if (mode === 'new') {
            state.newConversationIds.add(conversationKey);
            resetChatScroll();
        } else if (mode === 'existing') {
            state.shouldFollowLatest = true;
            chatMessages.classList.remove('new-conversation');
        }

        state.isRenderingMessages = true;

        chatMessages.innerHTML = '<div class="loader"><span></span><span></span><span></span></div>';
        toggleWelcomeCard(false);

        const response = await fetch(`api/messages.php?conversation_id=${id}`, { credentials: 'same-origin' });
        const data = await response.json();

        if (!data.success) {
            chatMessages.innerHTML = '<div class="message-bubble">Unable to load messages.</div>';
            state.isRenderingMessages = false;
            return;
        }

        const messages = data.data.messages || [];
        state.messageCounts.set(conversationKey, messages.length);
        if (!messages.length) {
            chatMessages.innerHTML = '';
            toggleWelcomeCard(true);
            state.isRenderingMessages = false;
            resetChatScroll();
            return;
        }

        chatMessages.innerHTML = messages.map((message) => {
            const role = message.role === 'user' ? 'user' : 'assistant';
            const content = escapeHtml(message.message_content || '');
            const imageSrc = message.image_path ? resolveImageUrl(message.image_path) : '';
            const imageMarkup = imageSrc ? `<img class="message-image" src="${imageSrc}" alt="Uploaded image">` : '';
            const textMarkup = content ? `<div>${content.replace(/\n/g, '<br>')}</div>` : '';
            return `
                <div class="message-row ${role}">
                    <div class="message-bubble">
                        ${imageMarkup}
                        ${textMarkup}
                        <span class="message-time">${formatDateTime(message.created_at)}</span>
                    </div>
                </div>
            `;
        }).join('');

        bindImageFallbacks(chatMessages);
        state.previousScrollTop = chatPage.scrollTop;
        state.isRenderingMessages = false;
        if (conversationIdField) conversationIdField.value = id;

        const isFirstNewMessage = state.newConversationIds.has(conversationKey) && messages.length <= 2;
        if (isFirstNewMessage) {
            await waitForImages(chatMessages);
            resetChatScroll();
            return;
        }

        state.newConversationIds.delete(conversationKey);
        chatMessages.classList.remove('new-conversation');
        await scrollExistingConversationToLatest();
    };

    const loadExistingConversation = (id) => loadMessages(id, 'existing');

    const sendFirstMessage = (id) => loadMessages(id, 'first-message');

    const sendSubsequentMessage = (id) => loadMessages(id, 'subsequent-message');

    const createNewConversation = async () => {
        const response = await fetch('api/conversations.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: new URLSearchParams({ action: 'create' }),
            credentials: 'same-origin',
        });

        const data = await response.json();
        if (data.success) {
            state.currentConversationId = String(data.data.conversation_id);
            rememberConversation(state.currentConversationId);
            state.newConversationIds.add(state.currentConversationId);
            resetChatScroll();
            await loadConversations();
            await loadMessages(state.currentConversationId, 'new');
        }
    };

    const renameConversation = async (id, title) => {
        const response = await fetch('api/conversations.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: new URLSearchParams({ action: 'rename', conversation_id: id, title }),
            credentials: 'same-origin',
        });
        const data = await response.json();
        if (data.success) {
            await loadConversations();
            await loadExistingConversation(id);
        }
    };

    const deleteConversation = async (id) => {
        const response = await fetch('api/conversations.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: new URLSearchParams({ action: 'delete', conversation_id: id }),
            credentials: 'same-origin',
        });

        const data = await response.json();
        if (data.success) {
            state.currentConversationId = '';
            rememberConversation('');
            if (conversationIdField) conversationIdField.value = '';
            await loadConversations();
            chatMessages.innerHTML = '';
            toggleWelcomeCard(true);
        }
    };

    const processImagePreview = (file) => {
        if (!file) return;
        const reader = new FileReader();
        reader.onload = (event) => {
            if (selectedImagePreview) {
                selectedImagePreview.src = event.target.result;
            }
            if (selectedImageBox) selectedImageBox.classList.remove('hidden');
        };
        reader.readAsDataURL(file);
        state.selectedImage = file;
    };

    const clearSelectedImage = () => {
        state.selectedImage = null;
        if (imageInput) imageInput.value = '';
        if (selectedImagePreview) selectedImagePreview.src = '';
        if (selectedImageBox) selectedImageBox.classList.add('hidden');
    };

    const handleSubmit = async (event) => {
        event.preventDefault();
        if (state.isSending) return;

        const prompt = messageInput.value.trim();
        if (!prompt && !state.selectedImage) return;
        clearChatError();

        const formData = new FormData();
        formData.append('message', prompt);
        formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

        if (state.currentConversationId) {
            formData.append('conversation_id', state.currentConversationId);
        }

        if (state.selectedImage) {
            formData.append('image', state.selectedImage);
        }

        const submittedConversationId = state.currentConversationId;
        const submittedConversationKey = String(submittedConversationId || '');
        const isNewConversation = !submittedConversationId || state.newConversationIds.has(submittedConversationKey);
        const previousMessageCount = state.messageCounts.get(submittedConversationKey) || 0;

        state.isSending = true;
        setSendState(true);
        state.shouldFollowLatest = true;

        let response;
        let json;
        try {
            response = await fetch('api/chat.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
            });
            json = await response.json();
        } catch (error) {
            showChatError(error instanceof TypeError ? 'network' : 'general');
            return;
        } finally {
            state.isSending = false;
            setSendState(false);
        }

        if (!response.ok || !json.success) {
            showChatError(json.data?.error_type || 'general');
            return;
        }

        messageInput.value = '';
        autoResize();
        clearSelectedImage();

        state.currentConversationId = String(json.data.conversation_id);
        rememberConversation(state.currentConversationId);
        if (isNewConversation && !submittedConversationId) {
            state.newConversationIds.add(state.currentConversationId);
        }
        if (conversationIdField) conversationIdField.value = state.currentConversationId;
        await loadConversations();
        if (isNewConversation && previousMessageCount === 0) {
            await sendFirstMessage(state.currentConversationId);
        } else {
            await sendSubsequentMessage(state.currentConversationId);
        }
    };

    if (generateButton) {
        generateButton.addEventListener('click', async () => {
            await createNewConversation();
        });
    }

    if (messageInput) {
        messageInput.addEventListener('input', autoResize);
        messageInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                form.requestSubmit();
            }
        });
    }

    if (form) {
        form.addEventListener('submit', handleSubmit);
    }

    if (imageInput) {
        imageInput.addEventListener('change', (event) => {
            const file = event.target.files[0];
            if (file) processImagePreview(file);
        });
    }

    if (removeImageBtn) {
        removeImageBtn.addEventListener('click', clearSelectedImage);
    }

    if (dismissChatError) {
        dismissChatError.addEventListener('click', clearChatError);
    }

    if (conversationSearch) {
        conversationSearch.addEventListener('input', (event) => {
            const value = event.target.value.trim();
            loadConversations(value);
        });
    }

    document.querySelectorAll('.prompt-chip').forEach((chip) => {
        chip.addEventListener('click', () => {
            const prompt = chip.getAttribute('data-prompt');
            if (messageInput) {
                messageInput.value = prompt;
                messageInput.focus();
                autoResize();
            }
        });
    });

    loadConversations();
    toggleWelcomeCard(true);
    if (messageInput) autoResize();
});
