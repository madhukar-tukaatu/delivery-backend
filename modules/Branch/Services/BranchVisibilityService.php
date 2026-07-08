<?php

namespace Modules\Branch\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Modules\Branch\Models\Branch;

class BranchVisibilityService
{
    public function visibleBranchIds(User $user): array
    {
        if ($this->isSystemAdmin($user)) {
            return Branch::query()
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        if (!$user->branch_id) {
            return [];
        }

        $branch = Branch::query()->find($user->branch_id);

        if (!$branch) {
            return [];
        }

        $type = $this->normalizeType($branch->type);

        if ($this->isHeadBranch($type)) {
            return $this->getSelfAndDescendantIds($branch);
        }

        if ($this->isBranchOrFranchise($type)) {
            return $this->getSelfAndDescendantIds($branch);
        }

        if ($type === 'sub_branch') {
            return [(int) $branch->id];
        }

        return [(int) $branch->id];
    }

    public function parentOptionsForCreate(User $user, ?string $creatingType = null): Collection
    {
        $creatingType = $this->normalizeType($creatingType);

        if ($this->isSystemAdmin($user)) {
            return $this->adminParentOptions($creatingType);
        }

        if (!$user->branch_id) {
            return new Collection();
        }

        $branch = Branch::query()->find($user->branch_id);

        if (!$branch) {
            return new Collection();
        }

        $userBranchType = $this->normalizeType($branch->type);

        if ($this->isHeadBranch($userBranchType)) {
            return $this->headBranchParentOptions($branch, $creatingType);
        }

        if ($this->isBranchOrFranchise($userBranchType)) {
            return $this->branchParentOptions($branch, $creatingType);
        }

        return new Collection();
    }

    private function adminParentOptions(?string $creatingType): Collection
    {
        if ($this->isHeadBranch($creatingType)) {
            return new Collection();
        }

        if ($this->isBranchOrFranchise($creatingType)) {
            return Branch::query()
                ->whereIn('type', ['head_branch', 'main_branch'])
                ->orderBy('name')
                ->get();
        }

        if (in_array($creatingType, ['sub_branch', 'pickup_point', 'delivery_hub'], true)) {
            return Branch::query()
                ->whereIn('type', ['head_branch', 'main_branch', 'branch', 'franchise_branch', 'sub_branch'])
                ->orderBy('name')
                ->get();
        }

        return Branch::query()
            ->whereIn('type', ['head_branch', 'main_branch', 'branch', 'franchise_branch', 'sub_branch'])
            ->orderBy('name')
            ->get();
    }

    private function headBranchParentOptions(Branch $branch, ?string $creatingType): Collection
    {
        if ($this->isHeadBranch($creatingType)) {
            return new Collection();
        }

        if ($this->isBranchOrFranchise($creatingType)) {
            return new Collection([$branch]);
        }

        if ($creatingType === 'sub_branch') {
            $ids = $this->getSelfAndDirectChildIds($branch, [
                'branch',
                'franchise_branch',
            ]);

            return Branch::query()
                ->whereIn('id', $ids)
                ->orderBy('name')
                ->get();
        }

        if (in_array($creatingType, ['pickup_point', 'delivery_hub'], true)) {
            $ids = $this->getSelfAndDescendantIds($branch);

            return Branch::query()
                ->whereIn('id', $ids)
                ->whereIn('type', ['head_branch', 'main_branch', 'branch', 'franchise_branch', 'sub_branch'])
                ->orderBy('name')
                ->get();
        }

        return new Collection([$branch]);
    }

    private function branchParentOptions(Branch $branch, ?string $creatingType): Collection
    {
        if ($creatingType === 'sub_branch') {
            return new Collection([$branch]);
        }

        if (in_array($creatingType, ['pickup_point', 'delivery_hub'], true)) {
            $ids = $this->getSelfAndDescendantIds($branch);

            return Branch::query()
                ->whereIn('id', $ids)
                ->whereIn('type', ['branch', 'franchise_branch', 'sub_branch'])
                ->orderBy('name')
                ->get();
        }

        return new Collection();
    }

    private function getSelfAndDirectChildIds(Branch $branch, array $childTypes = []): array
    {
        $query = Branch::query()
            ->where('parent_id', $branch->id);

        if ($childTypes) {
            $query->whereIn('type', $childTypes);
        }

        $childIds = $query
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique([
            (int) $branch->id,
            ...$childIds,
        ]));
    }

    private function getSelfAndDescendantIds(Branch $branch): array
    {
        $ids = [(int) $branch->id];

        $children = Branch::query()
            ->where('parent_id', $branch->id)
            ->get();

        foreach ($children as $child) {
            $ids = array_merge($ids, $this->getSelfAndDescendantIds($child));
        }

        return array_values(array_unique($ids));
    }

    private function isSystemAdmin(User $user): bool
    {
        return $user->hasRole('super_admin')
            || $user->hasRole('main_admin')
            || $user->hasRole('admin');
    }

    private function isHeadBranch(?string $type): bool
    {
        return in_array($type, ['head_branch', 'main_branch'], true);
    }

    private function isBranchOrFranchise(?string $type): bool
    {
        return in_array($type, ['branch', 'franchise_branch'], true);
    }

    private function normalizeType(?string $type): ?string
    {
        if (!$type) {
            return null;
        }

        $type = strtolower(trim($type));

        return match ($type) {
            'main', 'main_branch', 'head', 'head_branch' => 'head_branch',
            'normal_branch', 'regular_branch' => 'branch',
            'franchise', 'franchise_branch' => 'franchise_branch',
            'sub', 'subbranch', 'sub_branch' => 'sub_branch',
            'pickup', 'pickup_point' => 'pickup_point',
            'hub', 'delivery_hub' => 'delivery_hub',
            default => $type,
        };
    }
}