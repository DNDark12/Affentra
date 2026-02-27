<script setup>
import { computed } from 'vue';
import VueApexCharts from 'vue3-apexcharts';

const props = defineProps({
    series: {
        type: Array,
        required: true,
    },
    categories: {
        type: Array,
        required: true,
    },
    height: {
        type: [String, Number],
        default: 350,
    },
    colors: {
        type: Array,
        default: () => ['#0EA5E9', '#10B981', '#F59E0B'],
    }
});

const chartOptions = computed(() => ({
    chart: {
        type: 'line',
        toolbar: {
            show: false,
        },
        fontFamily: 'Inter, sans-serif',
        zoom: {
            enabled: false
        }
    },
    colors: props.colors,
    dataLabels: {
        enabled: false,
    },
    stroke: {
        curve: 'smooth',
        width: 3,
    },
    xaxis: {
        categories: props.categories,
        axisBorder: {
            show: false,
        },
        axisTicks: {
            show: false,
        },
        labels: {
            style: {
                colors: '#64748B',
                fontSize: '12px',
            },
        }
    },
    yaxis: {
        labels: {
            style: {
                colors: '#64748B',
                fontSize: '12px',
            },
            formatter: (value) => {
                if (value >= 1000000) {
                    return (value / 1000000).toFixed(1) + 'M';
                }
                if (value >= 1000) {
                    return (value / 1000).toFixed(1) + 'K';
                }
                return value;
            }
        },
    },
    grid: {
        borderColor: '#E2E8F0',
        strokeDashArray: 4,
        yaxis: {
            lines: {
                show: true
            }
        },
        padding: {
            top: 0,
            right: 0,
            bottom: 0,
            left: 10
        }
    },
    legend: {
        position: 'top',
        horizontalAlign: 'right',
        markers: {
            radius: 12,
        },
        itemMargin: {
            horizontal: 10,
            vertical: 0
        }
    },
    tooltip: {
        theme: 'light',
        y: {
            formatter: function (val) {
                return val.toLocaleString('vi-VN');
            }
        }
    }
}));
</script>

<template>
    <div class="line-chart-container">
        <VueApexCharts
            type="line"
            :height="height"
            :options="chartOptions"
            :series="series"
        />
    </div>
</template>

<style scoped>
.line-chart-container {
    width: 100%;
}
</style>
