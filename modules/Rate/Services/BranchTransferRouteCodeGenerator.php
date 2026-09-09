<?php

declare(strict_types=1);

namespace Modules\Rate\Services;

use Modules\Rate\Models\BranchTransferLane;
use Modules\Rate\Models\BranchTransferRoute;
use Modules\Rate\Models\ServiceType;
use Modules\Branch\Models\CoverageLocation;

final class BranchTransferRouteCodeGenerator
{
    /**
     * Generate route code based on origin, transits, destination, and service type.
     *
     * Format: {FROM}-{TRANSIT...}-{TO}-{SERVICE}
     * Example: KTM-PKR-MNG-STANDARD (Kathmandu -> Pokhara -> Mustang, Standard)
     *          KTM-PKR-STANDARD (direct, Kathmandu -> Pokhara, Standard)
     *
     * Service codes come from service_types.code column.
     *
     * @param int[] $transitBranchIds Ordered intermediate hub IDs.
     */
    public function generate(
        int $fromBranchId,
        int $toBranchId,
        string $serviceType = 'standard',
        array $transitBranchIds = []
    ): string {
        $fromBranch = CoverageLocation::find($fromBranchId);
        $toBranch = CoverageLocation::find($toBranchId);

        if (!$fromBranch || !$toBranch) {
            throw new \InvalidArgumentException('Invalid branch IDs provided');
        }

        // Get service type code from database
        $serviceTypeRecord = ServiceType::where('code', strtolower($serviceType))->first();
        if (!$serviceTypeRecord) {
            throw new \InvalidArgumentException("Invalid service type: {$serviceType}");
        }

        $segments = [$this->codeSegment($fromBranch)];

        foreach ($transitBranchIds as $transitId) {
            $transitBranch = CoverageLocation::find((int) $transitId);
            if ($transitBranch) {
                $segments[] = $this->codeSegment($transitBranch);
            }
        }

        $segments[] = $this->codeSegment($toBranch);
        $segments[] = strtoupper($serviceTypeRecord->code);

        return implode('-', $segments);
    }

    /**
     * Generate route name based on origin, destination, and transits.
     * Example: "Kathmandu to Pokhara" or "Kathmandu to Mustang via Pokhara".
     *
     * @param int[] $transitBranchIds Ordered intermediate hub IDs.
     */
    public function generateName(
        int $fromBranchId,
        int $toBranchId,
        array $transitBranchIds = []
    ): string {
        $fromBranch = CoverageLocation::find($fromBranchId);
        $toBranch = CoverageLocation::find($toBranchId);

        if (!$fromBranch || !$toBranch) {
            throw new \InvalidArgumentException('Invalid branch IDs provided');
        }

        $name = "{$fromBranch->name} to {$toBranch->name}";

        $transitNames = [];
        foreach ($transitBranchIds as $transitId) {
            $transitBranch = CoverageLocation::find((int) $transitId);
            if ($transitBranch) {
                $transitNames[] = $transitBranch->name;
            }
        }

        if (!empty($transitNames)) {
            $name .= ' via ' . implode(', ', $transitNames);
        }

        return $name;
    }

    /**
     * Build a distinguishing code segment from a branch.
     * Branch codes look like "TUK-KTM-MAIN" or "TUK-BAN-MAIN-2".
     * The distinguishing part is the city segment (KTM, BAN), not the shared "TUK" prefix.
     */
    private function codeSegment(CoverageLocation $branch): string
    {
        $code = trim((string) ($branch->code ?? ''));

        if (str_contains($code, '-')) {
            // "TUK-KTM-MAIN-2" -> ["TUK","KTM","MAIN","2"]
            $parts = array_filter(explode('-', $code), fn ($p) => $p !== '');

            // Drop the shared company prefix (TUK) and the generic "MAIN" word.
            $meaningful = array_values(array_filter(
                $parts,
                fn ($p) => !in_array(strtoupper($p), ['TUK', 'MAIN'], true)
            ));

            if (!empty($meaningful)) {
                // e.g. ["KTM"] -> "KTM"; ["BAN","2"] -> "BAN2"
                return strtoupper(implode('', $meaningful));
            }

            // Fallback: second segment if present.
            $parts = array_values($parts);
            if (isset($parts[1])) {
                return strtoupper($parts[1]);
            }
        }

        // No structured code — derive 3 letters from the branch name.
        $fromName = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', (string) ($branch->name ?? '')), 0, 3));

        return $fromName !== '' ? $fromName : 'UNK';
    }

    /**
     * Validate that a lane exists for the given origin-destination-service combination.
     */
    public function validateLaneExists(
        int $fromBranchId,
        int $toBranchId,
        string $serviceType = 'standard'
    ): bool {
        return BranchTransferLane::where('from_branch_id', $fromBranchId)
            ->where('to_branch_id', $toBranchId)
            ->where('service_type', strtolower($serviceType))
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Get service type code from database.
     */
    public function getServiceTypeCode(string $serviceType): ?string
    {
        $record = ServiceType::where('code', strtolower($serviceType))->first();
        return $record?->code;
    }
}
