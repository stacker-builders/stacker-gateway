<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import MemberAreaAppLayout from '@/Layouts/MemberAreaAppLayout.vue';
import Button from '@/components/ui/Button.vue';
import MemberAreaVideoPlayer from '@/components/MemberAreaVideoPlayer.vue';
import { renderLessonDescription } from '@/lib/lessonDescription';
import { completeMemberLesson, useMemberAreaHref } from '@/composables/useMemberAreaHref';
import { downloadMemberMaterial, isProtectedPdf } from '@/composables/downloadMemberMaterial';
import { useMemberAreaAdminPreview } from '@/composables/useMemberAreaAdminPreview';
import MemberLessonRatingStars from '@/components/member-area/MemberLessonRatingStars.vue';

defineOptions({ layout: MemberAreaAppLayout });

const props = defineProps({
    product: { type: Object, required: true },
    config: { type: Object, default: () => ({}) },
    lesson: { type: Object, required: true },
    slug: { type: String, required: true },
    base_url: { type: String, default: '' },
    comments_enabled: { type: Boolean, default: false },
    comments_require_approval: { type: Boolean, default: true },
    lesson_comments: { type: Array, default: () => [] },
});

function normalizePdfFiles(lesson) {
    const list = Array.isArray(lesson?.content_files) ? lesson.content_files : [];
    const normalized = list
        .map((it) => {
            if (typeof it === 'string') return { url: it, name: 'Material' };
            const url = (it?.url ?? '').toString().trim();
            if (!url) return null;
            return { url, name: (it?.name ?? 'Material').toString().trim() || 'Material' };
        })
        .filter(Boolean);
    if (normalized.length === 0 && lesson?.content_url) {
        normalized.push({ url: lesson.content_url, name: 'Material' });
    }
    return normalized;
}

const pdfFiles = computed(() => normalizePdfFiles(props.lesson));
const materialError = ref('');
const downloadingIndex = ref(null);

async function downloadProtectedPdf(file, index) {
    materialError.value = '';
    downloadingIndex.value = index;
    try {
        await downloadMemberMaterial(file.url, file.name || 'material.pdf');
    } catch (error) {
        materialError.value = error?.message || 'Não foi possível proteger este PDF.';
    } finally {
        downloadingIndex.value = null;
    }
}

const { href } = useMemberAreaHref(props.slug, props.base_url);
const { isAdminPreview } = useMemberAreaAdminPreview();

const completed = ref(props.lesson.is_completed ?? false);
const lessonRating = ref(props.lesson.rating ?? null);
const commentContent = ref('');
const commentSubmitting = ref(false);
let autoCompleteTimer = null;

async function markComplete() {
    if (isAdminPreview.value || completed.value) return;
    completed.value = true;
    try {
        await completeMemberLesson(href(`/aula/${props.lesson.id}/complete`));
    } catch (_) {
        completed.value = false;
    }
}

/** Vídeo: marcar concluído ao assistir 80% ou ao terminar. */
function scheduleAutoComplete() {
    if (!props.lesson || completed.value) return;
    if (props.lesson.type !== 'video' || !props.lesson.content_url) return;
    const durationSeconds = Math.max(30, Math.floor((props.lesson.duration_seconds || 60) * 0.8));
    autoCompleteTimer = setTimeout(() => markComplete(), durationSeconds * 1000);
}

/** Link, PDF, texto, etc.: marcar concluído ao exibir. */
function shouldAutoCompleteNonVideo() {
    if (!props.lesson || completed.value) return false;
    const t = props.lesson.type;
    return t === 'link' || t === 'pdf' || t === 'text' || (t !== 'video' && (props.lesson.content_url || props.lesson.content_text));
}

onMounted(() => {
    if (isAdminPreview.value) return;
    if (props.lesson?.is_completed) completed.value = true;
    else if (props.lesson?.type === 'video') scheduleAutoComplete();
    else if (shouldAutoCompleteNonVideo()) setTimeout(() => markComplete(), 500);
});

onUnmounted(() => {
    if (autoCompleteTimer) clearTimeout(autoCompleteTimer);
});

function submitComment() {
    if (!props.comments_enabled || !commentContent.value?.trim()) return;
    commentSubmitting.value = true;
    router.post(href(`/aula/${props.lesson.id}/comments`), { content: commentContent.value.trim() }, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { commentSubmitting.value = false; commentContent.value = ''; },
    });
}
function formatCommentDate(iso) {
    if (!iso) return '';
    try {
        const d = new Date(iso);
        return d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    } catch (_) { return iso; }
}
</script>

<template>
    <div class="space-y-6">
        <div class="flex items-center gap-2 text-sm text-zinc-400">
            <Link :href="href('/modulos')" class="hover:text-[var(--ma-primary)]">Módulos</Link>
            <span v-if="lesson.section"> / {{ lesson.section.title }}</span>
            <span v-if="lesson.module"> / {{ lesson.module.title }}</span>
        </div>
        <h1 class="text-2xl font-bold">{{ lesson.title }}</h1>

        <div class="rounded-xl border border-zinc-700 bg-zinc-800/50 overflow-hidden">
            <template v-if="lesson.type === 'video'">
                <MemberAreaVideoPlayer
                    v-if="lesson.content_url"
                    :src="lesson.content_url"
                    :watermark-enabled="!!lesson.watermark_enabled"
                    :watermark-data="lesson.student ?? null"
                    @ended="markComplete"
                />
                <div
                    v-if="lesson.content_text"
                    class="lesson-rich-text prose prose-invert max-w-none border-t border-zinc-700 p-6"
                    v-html="renderLessonDescription(lesson.content_text)"
                />
                <div v-if="!lesson.content_url && !lesson.content_text" class="p-8 text-center text-zinc-500">
                    Conteúdo não disponível.
                </div>
            </template>
            <template v-else-if="lesson.type === 'link' && lesson.content_url">
                <div class="p-6">
                    <a :href="lesson.content_url" target="_blank" rel="noopener" class="inline-flex items-center gap-2 text-[var(--ma-primary)] hover:underline">
                        {{ lesson.link_title?.trim() || 'Abrir link externo' }}
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" /></svg>
                    </a>
                </div>
            </template>
            <div v-else-if="lesson.type === 'link' && lesson.content_text" class="lesson-rich-text prose prose-invert max-w-none border-t border-zinc-700 p-6" v-html="renderLessonDescription(lesson.content_text)" />
            <template v-else-if="lesson.type === 'pdf' && pdfFiles.length">
                <div class="p-6">
                    <div class="space-y-2">
                        <template v-for="(f, i) in pdfFiles" :key="`${f.url}-${i}`">
                            <button
                                v-if="isProtectedPdf(lesson, f)"
                                type="button"
                                class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-[var(--ma-primary)] px-4 py-2.5 font-medium text-white transition hover:opacity-90 disabled:opacity-60"
                                :disabled="downloadingIndex === i"
                                @click="downloadProtectedPdf(f, i)"
                            >
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                                {{ downloadingIndex === i ? 'Preparando PDF...' : (f.name || 'Baixar material') }}
                            </button>
                            <a
                                v-else
                                :href="f.url"
                                download
                                target="_blank"
                                rel="noopener"
                                class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-[var(--ma-primary)] px-4 py-2.5 font-medium text-white transition hover:opacity-90"
                            >
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                                {{ f.name || 'Baixar material' }}
                            </a>
                        </template>
                        <p v-if="materialError" class="text-sm text-red-300">{{ materialError }}</p>
                    </div>
                </div>
            </template>
            <div v-else-if="lesson.type === 'pdf' && lesson.content_text" class="lesson-rich-text prose prose-invert max-w-none border-t border-zinc-700 p-6" v-html="renderLessonDescription(lesson.content_text)" />
            <template v-else-if="lesson.type === 'text' && lesson.content_text">
                <div class="lesson-rich-text prose prose-invert max-w-none p-6" v-html="renderLessonDescription(lesson.content_text)" />
            </template>
            <div v-else class="p-8 text-center text-zinc-500">
                Conteúdo não disponível.
            </div>
        </div>

        <MemberLessonRatingStars
            v-if="!isAdminPreview"
            v-model="lessonRating"
            :url="href(`/aula/${lesson.id}/rating`)"
        />

        <div class="flex items-center justify-between">
            <Link :href="href('/modulos')" class="text-sm text-zinc-400 hover:text-[var(--ma-primary)]">← Voltar aos módulos</Link>
            <Button v-if="!isAdminPreview" @click="markComplete" :disabled="completed">
                {{ completed ? 'Concluído' : 'Marcar como concluído' }}
            </Button>
        </div>

        <!-- Comentários da aula -->
        <section v-if="comments_enabled" class="rounded-xl border border-zinc-700 bg-zinc-800/50 p-4 space-y-4">
            <h2 class="text-lg font-semibold">Comentários</h2>
            <ul class="space-y-3">
                <li v-for="c in lesson_comments" :key="c.id" class="flex gap-3 border-b border-zinc-700/50 pb-3 last:border-0 last:pb-0">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-full bg-[var(--ma-primary)]/20 text-sm font-semibold text-[var(--ma-primary)]">
                        <img v-if="c.user?.avatar_url" :src="c.user.avatar_url" :alt="c.user.name" class="h-full w-full object-cover" />
                        <span v-else>{{ (c.user?.name ?? 'A').split(/\s+/).map(n => n[0]).slice(0, 2).join('').toUpperCase() || 'A' }}</span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-zinc-300">{{ c.user?.name ?? 'Aluno' }}</p>
                        <p class="text-sm text-zinc-400 mt-0.5">{{ c.content }}</p>
                        <p class="text-xs text-zinc-500 mt-1">{{ formatCommentDate(c.created_at) }}</p>
                    </div>
                </li>
            </ul>
            <p v-if="!lesson_comments?.length" class="text-sm text-zinc-500">Nenhum comentário ainda.</p>
            <form v-if="!isAdminPreview" @submit.prevent="submitComment" class="space-y-2">
                <textarea
                    v-model="commentContent"
                    rows="3"
                    class="w-full rounded-lg border border-zinc-600 bg-zinc-800 px-3 py-2 text-sm text-white placeholder-zinc-500 focus:border-[var(--ma-primary)] focus:ring-1 focus:ring-[var(--ma-primary)]"
                    placeholder="Escreva um comentário..."
                    maxlength="2000"
                />
                <Button type="submit" :disabled="commentSubmitting || !commentContent?.trim()">
                    {{ commentSubmitting ? 'Enviando…' : 'Enviar comentário' }}
                </Button>
            </form>
            <p v-if="!isAdminPreview && comments_require_approval" class="text-xs text-zinc-500">Seus comentários serão publicados após aprovação do instrutor.</p>
        </section>
    </div>
</template>
