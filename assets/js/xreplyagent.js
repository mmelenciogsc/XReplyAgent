(function () {
    'use strict';

    const app = window.XReplyAgentApp || {};

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function formatScoreLabel(value) {
        const numeric = Number.parseFloat(String(value ?? 0));
        if (!Number.isFinite(numeric)) {
            return '0/100';
        }

        const normalizedValue = numeric <= 10 ? numeric * 10 : numeric / 5;
        const normalized = Math.max(0, Math.min(100, Math.round(normalizedValue)));
        return `${normalized}/100`;
    }

    function setLinkContent(node, url, label, emptyLabel) {
        if (!(node instanceof HTMLElement)) {
            return;
        }

        node.replaceChildren();
        const value = String(url || '').trim();
        if (value === '') {
            node.textContent = emptyLabel || '';
            return;
        }

        const link = document.createElement('a');
        link.className = 'xra-inline-link';
        link.href = value;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.textContent = label;
        node.appendChild(link);
    }

    function formatPlainResult(payload) {
        if (!payload) {
            return 'No response yet.';
        }

        if (payload.ok === false) {
            return `Error: ${String(payload.error || 'Request failed.')}`;
        }

        const analysis = payload.analysis || {};
        const candidates = Array.isArray(payload.reply_candidates) ? payload.reply_candidates : [];
        const lines = [
            `Status: ${String(payload.reply_set?.status || 'review_queue')}`,
            `Provider: ${String(payload.provider || 'mock')}`,
            `Main Topic: ${String(analysis.main_topic || 'Unknown')}`,
            `Tone: ${String(analysis.tone || 'Unknown')}`,
            `Sentiment: ${String(analysis.sentiment || 'Unknown')}`,
            `Likely Intent: ${String(analysis.likely_intent || 'Unknown')}`,
            '',
            'Reply Candidates:',
        ];

        candidates.forEach((candidate, index) => {
            lines.push(
                `${index + 1}. ${String(candidate.approach_label || 'Reply')} | ${String(candidate.reply_text || '')} | Score ${formatScoreLabel(candidate.total_score || 0)}`
            );
        });

        if (payload.recommendations && payload.recommendations.summary) {
            lines.push('', `Recommendations: ${String(payload.recommendations.summary)}`);
        }

        if (payload.duplicate_of_post_id) {
            lines.push('', `Duplicate Of Post ID: ${String(payload.duplicate_of_post_id)}`);
        }

        return lines.join('\n');
    }

    function setLiveMessage(root, message) {
        if (!(root instanceof HTMLElement)) {
            return;
        }

        const region = root.querySelector('[data-xra-live-region]');
        if (region instanceof HTMLElement) {
            region.textContent = message;
        }
    }

    function setAnalysisOutput(root, payload) {
        if (!(root instanceof HTMLElement)) {
            return;
        }

        const output = root.querySelector('[data-xra-analysis-output]');
        if (output instanceof HTMLElement) {
            output.textContent = formatPlainResult(payload);
        }
    }

    function refreshSummary(summary) {
        if (!summary || typeof summary !== 'object') {
            return;
        }

        Object.keys(summary).forEach((key) => {
            const value = summary[key];
            document.querySelectorAll(`[data-xra-stat="${key}"]`).forEach((node) => {
                node.textContent = String(value ?? 0);
            });
        });
    }

    async function fetchJson(path, method, body) {
        const response = await fetch(`${app.restUrl}${path}`, {
            method,
            headers: {
                'Content-Type': 'application/json',
                ...(app.nonce ? { 'X-XRA-Nonce': app.nonce } : {}),
            },
            body: body ? JSON.stringify(body) : undefined,
        });

        return response.json();
    }

    function browserPanel() {
        const panel = document.querySelector('[data-xra-browser-panel]');
        if (!(panel instanceof HTMLElement)) {
            return;
        }

        const replySetId = panel.getAttribute('data-reply-set-id') || '';
        if (replySetId === '') {
            return;
        }

        const statusNode = panel.querySelector('[data-xra-browser-field="status"]');
        const phaseNode = panel.querySelector('[data-xra-browser-field="phase"]');
        const stepNode = panel.querySelector('[data-xra-browser-field="step"]');
        const targetNode = panel.querySelector('[data-xra-browser-field="target_url"]');
        const publishedNode = panel.querySelector('[data-xra-browser-field="published_url"]');
        const completedNode = panel.querySelector('[data-xra-browser-field="completed_at"]');
        const previewNode = panel.querySelector('.xra-browser-preview');
        const screenshotNode = panel.querySelector('[data-xra-browser-screenshot]');
        const eventsList = panel.querySelector('[data-xra-browser-events]');

        let timer = null;

        const render = async () => {
            try {
                const payload = await fetchJson(`/browser-jobs?reply_set_id=${encodeURIComponent(replySetId)}`, 'GET');
                const job = payload?.job || {};
                const events = Array.isArray(payload?.events) ? payload.events : [];
                if (statusNode instanceof HTMLElement) {
                    statusNode.textContent = String(job.status || 'idle');
                }
                if (phaseNode instanceof HTMLElement) {
                    phaseNode.textContent = String(job.phase || '');
                }
                if (stepNode instanceof HTMLElement) {
                    stepNode.textContent = String(job.current_step || '');
                }
                if (targetNode instanceof HTMLElement) {
                    setLinkContent(targetNode, job.target_url || '', 'Open Source', 'Pending');
                }
                if (publishedNode instanceof HTMLElement) {
                    setLinkContent(publishedNode, job.published_url || '', 'Open Reply', 'Pending');
                }
                if (completedNode instanceof HTMLElement) {
                    completedNode.textContent = String(job.completed_at || '');
                }
                if (previewNode instanceof HTMLElement) {
                    if (job.latest_screenshot_url) {
                        const img = document.createElement('img');
                        img.setAttribute('data-xra-browser-screenshot', '');
                        img.alt = 'Latest browser screenshot';
                        img.src = String(job.latest_screenshot_url);
                        previewNode.replaceChildren(img);
                    } else {
                        previewNode.replaceChildren();
                        const placeholder = document.createElement('div');
                        placeholder.className = 'xra-browser-placeholder';
                        placeholder.textContent = 'Waiting for the first screenshot.';
                        previewNode.appendChild(placeholder);
                    }
                }
                if (screenshotNode instanceof HTMLImageElement && job.latest_screenshot_url) {
                    screenshotNode.src = String(job.latest_screenshot_url);
                }
                if (eventsList instanceof HTMLElement) {
                    eventsList.innerHTML = events.map((event) => {
                        return `<li><strong>${escapeHtml(event.event_type || 'event')}</strong><span>${escapeHtml(event.message || '')}</span></li>`;
                    }).join('');
                }

                const active = ['queued', 'running', 'paused'].includes(String(job.status || ''));
                if (!active && timer) {
                    window.clearInterval(timer);
                    timer = null;
                }
            } catch (error) {
                console.warn('Browser panel refresh failed', error);
            }
        };

        render();
        timer = window.setInterval(render, 3000);
    }

    function walkthroughGallery() {
        const root = document.querySelector('[data-xra-walkthrough-gallery]');
        if (!(root instanceof HTMLElement)) {
            return;
        }

        const manifestScript = root.querySelector('[data-xra-walkthrough-manifest]');
        if (!(manifestScript instanceof HTMLScriptElement)) {
            return;
        }

        let manifest = null;
        try {
            manifest = JSON.parse(manifestScript.textContent || '{}');
        } catch (error) {
            manifest = null;
        }

        const items = Array.isArray(manifest?.slides) ? manifest.slides : [];
        if (items.length === 0) {
            return;
        }

        const deviceQuery = window.matchMedia('(max-width: 767px)');
        let deviceMode = deviceQuery.matches ? 'mobile' : 'desktop';
        const serverDeviceMode = root.getAttribute('data-xra-device-mode');
        if (serverDeviceMode === 'mobile' || serverDeviceMode === 'desktop') {
            deviceMode = deviceQuery.matches ? 'mobile' : 'desktop';
        }
        let currentIndex = 0;

        const buttons = Array.from(root.querySelectorAll('[data-xra-walkthrough-index]'));
        const prevButton = root.querySelector('[data-xra-walkthrough-prev]');
        const nextButton = root.querySelector('[data-xra-walkthrough-next]');
        const toggleButton = root.querySelector('[data-xra-walkthrough-toggle]');
        const counterNode = root.querySelector('[data-xra-walkthrough-counter]');
        const videoNode = root.querySelector('[data-xra-walkthrough-video]');
        const videoSourceNode = root.querySelector('[data-xra-walkthrough-source]');
        const videoTrackNode = root.querySelector('[data-xra-walkthrough-track]');
        const liveRegion = root.querySelector('[data-xra-walkthrough-live]');

        function resolveMedia(item) {
            if (!item || typeof item !== 'object' || !item.video || typeof item.video !== 'object') {
                return { src: '', poster: '', captions: '' };
            }

            const chosen = item.video[deviceMode] || item.video.desktop || item.video.mobile || {};
            return {
                src: typeof chosen.src === 'string' ? chosen.src : '',
                poster: typeof chosen.poster === 'string' ? chosen.poster : '',
                captions: typeof chosen.captions === 'string' ? chosen.captions : '',
            };
        }

        function syncToggleButton() {
            if (!(toggleButton instanceof HTMLButtonElement) || !(videoNode instanceof HTMLVideoElement)) {
                return;
            }

            const isPlaying = !videoNode.paused && !videoNode.ended;
            toggleButton.textContent = isPlaying ? 'Pause' : 'Play';
            toggleButton.setAttribute('aria-pressed', isPlaying ? 'true' : 'false');
        }

        function setActive(index) {
            const safeIndex = ((index % items.length) + items.length) % items.length;
            currentIndex = safeIndex;
            const item = items[currentIndex] || {};
            const media = resolveMedia(item);

            if (counterNode instanceof HTMLElement) {
                counterNode.textContent = `${currentIndex + 1} / ${items.length}`;
            }

            if (videoNode instanceof HTMLVideoElement) {
                const previousTime = Number.isFinite(videoNode.currentTime) ? videoNode.currentTime : 0;
                const wasPlaying = !videoNode.paused && !videoNode.ended;
                const shouldRestoreTime = wasPlaying && previousTime > 0;
                videoNode.pause();
                if (media.poster) {
                    videoNode.poster = media.poster;
                }
                if (videoSourceNode instanceof HTMLSourceElement) {
                    videoSourceNode.src = media.src;
                    videoSourceNode.type = 'video/mp4';
                    videoNode.load();
                } else if (media.src) {
                    videoNode.src = media.src;
                    videoNode.load();
                }
                if (videoTrackNode instanceof HTMLTrackElement) {
                    videoTrackNode.src = media.captions || '';
                }
                videoNode.setAttribute('aria-label', item.title || 'Walkthrough');

                if (shouldRestoreTime) {
                    videoNode.addEventListener('loadedmetadata', function restoreTime() {
                        videoNode.removeEventListener('loadedmetadata', restoreTime);
                        try {
                            videoNode.currentTime = Math.min(previousTime, Math.max(0, videoNode.duration - 0.1));
                        } catch (_error) {
                            // Ignore seek failures.
                        }
                        if (wasPlaying) {
                            videoNode.play().catch(() => {});
                        }
                        syncToggleButton();
                    });
                } else {
                    videoNode.addEventListener('loadedmetadata', function resetTime() {
                        videoNode.removeEventListener('loadedmetadata', resetTime);
                        try {
                            videoNode.currentTime = 0;
                        } catch (_error) {
                            // Ignore seek failures.
                        }
                        syncToggleButton();
                    });
                }
            }

            if (liveRegion instanceof HTMLElement) {
                liveRegion.textContent = `${item.title || 'Walkthrough'} loaded for ${deviceMode} viewing.`;
            }

            buttons.forEach((button) => {
                const buttonIndex = Number(button.getAttribute('data-xra-walkthrough-index') || '-1');
                const active = buttonIndex === currentIndex;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            });

            syncToggleButton();
        }

        buttons.forEach((button) => {
            button.addEventListener('click', () => {
                const index = Number(button.getAttribute('data-xra-walkthrough-index') || '0');
                setActive(Number.isFinite(index) ? index : 0);
            });
        });

        if (prevButton instanceof HTMLButtonElement) {
            prevButton.addEventListener('click', () => {
                setActive(currentIndex - 1);
            });
        }

        if (toggleButton instanceof HTMLButtonElement && videoNode instanceof HTMLVideoElement) {
            toggleButton.addEventListener('click', () => {
                if (videoNode.paused || videoNode.ended) {
                    videoNode.play().catch(() => {});
                } else {
                    videoNode.pause();
                }
            });
        }

        if (nextButton instanceof HTMLButtonElement) {
            nextButton.addEventListener('click', () => {
                setActive(currentIndex + 1);
            });
        }

        if (typeof deviceQuery.addEventListener === 'function') {
            deviceQuery.addEventListener('change', () => {
                deviceMode = deviceQuery.matches ? 'mobile' : 'desktop';
                setActive(currentIndex);
            });
        }

        if (videoNode instanceof HTMLVideoElement) {
            videoNode.addEventListener('play', syncToggleButton);
            videoNode.addEventListener('pause', syncToggleButton);
            videoNode.addEventListener('ended', syncToggleButton);
        }

        setActive(0);
    }

    function fullscreenToggle() {
        document.querySelectorAll('[data-xra-fullscreen]').forEach((button) => {
            button.addEventListener('click', () => {
                if (document.fullscreenElement) {
                    document.exitFullscreen().catch(() => {});
                    return;
                }

                document.documentElement.requestFullscreen?.().catch(() => {});
            });
        });
    }

    function copyButtons() {
        document.querySelectorAll('[data-xra-copy]').forEach((button) => {
            button.addEventListener('click', async () => {
                const value = button.getAttribute('data-xra-copy-value') || '';
                if (value === '') {
                    return;
                }

                try {
                    await navigator.clipboard.writeText(value);
                    button.textContent = 'Copied';
                    window.setTimeout(() => {
                        button.textContent = 'Copy';
                    }, 1200);
                } catch (error) {
                    console.warn('Copy failed', error);
                }
            });
        });
    }

    function workflowForm() {
        const form = document.querySelector('[data-xra-workflow-form]');
        const response = document.querySelector('[data-xra-response]');
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const submit = form.querySelector('button[type="submit"]');
            if (submit instanceof HTMLButtonElement) {
                submit.disabled = true;
            }

            const payload = Object.fromEntries(new FormData(form).entries());
            if (response instanceof HTMLElement) {
                response.classList.add('is-loading');
            }
            setLiveMessage(response, 'Running workflow.');
            setAnalysisOutput(response, { ok: true, analysis: {}, reply_candidates: [], recommendations: { summary: 'Working...' } });

            try {
                const result = await fetchJson('/workflow', 'POST', payload);
                setAnalysisOutput(response, result);
                setLiveMessage(response, result?.ok === false ? String(result.error || 'Workflow failed.') : 'Workflow complete.');
                if (result?.ok) {
                    const health = await fetchJson('/health', 'GET');
                    refreshSummary(health.summary || {});
                }
            } catch (error) {
                const message = error instanceof Error ? error.message : 'Unexpected error.';
                setAnalysisOutput(response, { ok: false, error: message });
                setLiveMessage(response, message);
            } finally {
                if (response instanceof HTMLElement) {
                    response.classList.remove('is-loading');
                }
                if (submit instanceof HTMLButtonElement) {
                    submit.disabled = false;
                }
            }
        });
    }

    function testConnection() {
        const button = document.querySelector('[data-xra-test-connection]');
        const status = document.querySelector('[data-xra-connection-status]');
        if (!(button instanceof HTMLButtonElement) || !(status instanceof HTMLElement)) {
            return;
        }

        button.addEventListener('click', async () => {
            button.disabled = true;
            status.textContent = 'Testing connection...';
            try {
                const health = await fetchJson('/health', 'GET');
                const provider = health.provider || {};
                status.textContent = `Healthy. Provider: ${provider.provider || 'mock'} | Model: ${provider.model || 'gpt-4o-mini'}`;
            } catch (error) {
                status.textContent = error instanceof Error ? error.message : 'Connection failed.';
            } finally {
                button.disabled = false;
            }
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        fullscreenToggle();
        copyButtons();
        workflowForm();
        testConnection();
        walkthroughGallery();
        browserPanel();
    });
})();
