<script setup lang="ts">
import { computed } from 'vue';

import type { PublicationRetention } from '@/types/analytics';

const props = defineProps<{
    retention: PublicationRetention;
    testId: string;
}>();

const WIDTH = 320;
const HEIGHT = 96;

/** The top of the chart: 100% watching, or more where people rewatched. */
const ceiling = computed((): number =>
    Math.max(1, ...props.retention.points.map((point) => point.watch_ratio)),
);

const path = computed((): string =>
    props.retention.points
        .map((point, index) => {
            const x = point.at * WIDTH;
            const y = HEIGHT - (point.watch_ratio / ceiling.value) * HEIGHT;

            return `${index === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`;
        })
        .join(' '),
);

/** The 100% line, drawn only when rewatches pushed the curve above it. */
const fullLineY = computed((): number | null =>
    ceiling.value > 1 ? HEIGHT - HEIGHT / ceiling.value : null,
);

const startPercent = computed((): number =>
    Math.round((props.retention.points[0]?.watch_ratio ?? 0) * 100),
);

const endPercent = computed((): number =>
    Math.round((props.retention.points.at(-1)?.watch_ratio ?? 0) * 100),
);
</script>

<template>
    <section class="space-y-2" :data-testid="testId">
        <div class="flex items-baseline justify-between gap-2">
            <h3 class="text-sm font-medium text-foreground">
                {{ $t('posts.publish.retention.title') }}
            </h3>
            <span class="text-xs text-muted-foreground">
                {{
                    $t('posts.publish.retention.as_of', {
                        date: retention.as_of,
                    })
                }}
            </span>
        </div>
        <svg
            :viewBox="`0 0 ${WIDTH} ${HEIGHT}`"
            class="h-24 w-full overflow-visible text-primary"
            preserveAspectRatio="none"
            role="img"
            :aria-label="
                $t('posts.publish.retention.summary', {
                    start: String(startPercent),
                    end: String(endPercent),
                })
            "
        >
            <line
                v-if="fullLineY !== null"
                x1="0"
                :y1="fullLineY"
                :x2="WIDTH"
                :y2="fullLineY"
                class="stroke-border"
                stroke-dasharray="3 3"
                vector-effect="non-scaling-stroke"
            />
            <line
                x1="0"
                :y1="HEIGHT"
                :x2="WIDTH"
                :y2="HEIGHT"
                class="stroke-border"
                vector-effect="non-scaling-stroke"
            />
            <path
                :d="path"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linejoin="round"
                vector-effect="non-scaling-stroke"
            />
        </svg>
        <div class="flex justify-between text-xs text-muted-foreground">
            <span
                >{{ $t('posts.publish.retention.start') }} ·
                {{ startPercent }}%</span
            >
            <span
                >{{ $t('posts.publish.retention.end') }} ·
                {{ endPercent }}%</span
            >
        </div>
    </section>
</template>
