window.SUXI_SHARED_COMPONENTS = (() => {
    const CompassCardHeader = {
        props: ['icon', 'iconColor', 'title'],
        template: `
            <div class="compass-card-header flex items-center justify-between">
                <h4 class="font-semibold text-gray-800 flex items-center">
                    <i :class="[icon, iconColor, 'mr-2']"></i>{{ title }}
                </h4>
                <slot></slot>
            </div>
        `
    };

    const MetricCard = {
        props: ['label', 'value'],
        template: `
            <div class="metric-card">
                <div class="text-xs text-gray-400 mb-1">{{ label }}</div>
                <div class="metric-value">{{ value }}</div>
            </div>
        `
    };

    const SearchInput = {
        props: ['modelValue', 'placeholder'],
        emits: ['update:modelValue'],
        template: `
            <div class="relative">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                <input :value="modelValue" @input="$emit('update:modelValue', $event.target.value)"
                    :placeholder="placeholder" class="input-field pl-10 w-64">
            </div>
        `
    };

    const StatusFilter = {
        props: ['modelValue'],
        emits: ['update:modelValue'],
        template: `
            <select :value="modelValue" @change="$emit('update:modelValue', $event.target.value)" class="input-field">
                <option value="">全部状态</option>
                <option value="1">启用</option>
                <option value="0">禁用</option>
            </select>
        `
    };

    const StatusBadge = {
        props: ['status', 'canToggle'],
        emits: ['toggle'],
        template: `
            <button v-if="canToggle" @click="$emit('toggle')"
                :class="String(status) === '1' ? 'badge-success' : 'badge-danger'"
                class="badge cursor-pointer hover:shadow-md transition">
                {{ String(status) === '1' ? '启用' : '禁用' }}
            </button>
            <span v-else :class="String(status) === '1' ? 'badge-success' : 'badge-danger'" class="badge">
                {{ String(status) === '1' ? '正常' : '禁用' }}
            </span>
        `
    };

    const RoleBadge = {
        props: ['role'],
        template: `
            <span :class="{
                'badge-danger': role?.level === 1,
                'badge-warning': role?.level === 2,
                'badge-info': role?.level === 3
            }" class="badge">
                {{ role?.display_name || '未知' }}
            </span>
        `
    };

    const ActionButtons = {
        props: ['canEdit', 'canDelete'],
        emits: ['edit', 'delete'],
        template: `
            <div class="flex items-center gap-2">
                <button v-if="canEdit" @click="$emit('edit')" class="action-btn action-btn-edit" title="编辑">
                    <i class="fas fa-edit"></i>
                </button>
                <button v-if="canDelete" @click="$emit('delete')" class="action-btn action-btn-delete" title="删除">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        `
    };

    const DataTable = {
        props: ['title', 'columns', 'data', 'canAdd', 'addText', 'emptyIcon', 'emptyText'],
        emits: ['add'],
        template: `
            <div class="card">
                <div class="p-5 border-b border-gray-100 flex flex-wrap justify-between items-center gap-4">
                    <div class="flex items-center gap-3">
                        <slot name="filters"></slot>
                    </div>
                    <button v-if="canAdd" @click="$emit('add')" class="btn-primary">
                        <i class="fas fa-plus mr-2"></i>{{ addText }}
                    </button>
                </div>
                <div class="table-container overflow-x-auto">
                    <table v-if="data && data.length > 0" class="w-full">
                        <thead>
                            <tr>
                                <th v-for="col in columns" :key="col.key" class="px-6 py-4 text-left">{{ col.label }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in data" :key="item.id || item.name">
                                <slot name="row" :item="item"></slot>
                            </tr>
                        </tbody>
                    </table>
                    <div v-else class="empty-state text-center">
                        <i :class="[emptyIcon || 'fas fa-inbox', 'block mx-auto']"></i>
                    <p>{{ emptyText || '数据依据不足' }}</p>
                    </div>
                </div>
            </div>
        `
    };

    return {
        CompassCardHeader,
        MetricCard,
        SearchInput,
        StatusFilter,
        StatusBadge,
        RoleBadge,
        ActionButtons,
        DataTable,
    };
})();
