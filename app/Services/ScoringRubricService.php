<?php

namespace App\Services;

use App\Models\Criterion;

class ScoringRubricService
{
    public function for(Criterion $criterion): array
    {
        $custom = $criterion->rubric ?? [];
        if (count($custom) === 5 && collect(range(1, 5))->every(fn ($score) => isset($custom[$score]) && is_string($custom[$score]) && trim($custom[$score]) !== '')) {
            return ['source' => 'institution', 'label' => 'Institution-defined scoring guide', 'levels' => array_combine(range(1, 5), array_map(fn ($score) => $custom[$score], range(1, 5)))];
        }
        $defaults = [
            'PERFORMANCE' => [
                'Meets operational requirements; no meaningful performance shortfall.',
                'Minor slowdowns with little effect on normal work.',
                'Recurring slowdowns affect some required tasks.',
                'Frequent performance limitations disrupt important tasks.',
                'Severe performance deficiency prevents essential services from meeting requirements.',
            ],
            'CONDITION' => [
                'Good technical and physical condition; no observed faults.',
                'Minor wear or isolated issues with little operational effect.',
                'Noticeable deterioration or occasional faults require attention.',
                'Repeated faults or major deterioration reduce reliability.',
                'Failing or unstable condition threatens continued service.',
            ],
            'DOWNTIME' => [
                'Service interruptions are rare and have negligible impact.',
                'Occasional brief interruptions with limited impact.',
                'Recurring interruptions noticeably affect users or services.',
                'Frequent or lengthy interruptions disrupt important activities.',
                'Frequent severe or prolonged outages compromise essential services.',
            ],
            'UTILIZATION' => [
                'Ample capacity headroom under normal and peak workloads.',
                'Generally comfortable capacity with only minor pressure.',
                'Capacity sometimes approaches its limits during busy periods.',
                'Capacity regularly reaches its limits and affects service quality.',
                'Sustained overload or congestion prevents required service levels.',
            ],
            'CRITICALITY' => [
                'Limited convenience impact; suitable alternatives are readily available.',
                'Affects a small group with workable alternatives.',
                'Supports several users or services; disruption has a moderate institutional impact.',
                'Supports major institutional functions with limited practical workarounds.',
                'Essential campus service with no practical alternative and severe disruption impact.',
            ],
            'SECURITY' => [
                'Supported infrastructure with appropriate controls and low observed exposure.',
                'Minor security gaps that can be addressed with routine maintenance.',
                'Noticeable control gaps or vulnerabilities create meaningful exposure.',
                'Significant vulnerabilities or unsupported controls create high exposure.',
                'Severe, inadequately mitigated exposure threatens essential services or information.',
            ],
            'COST' => [
                'Low upgrade financial burden; funding is readily manageable.',
                'Manageable upgrade cost within normal planning capacity.',
                'Moderate financial burden requiring explicit budget planning.',
                'High financial burden requiring substantial budget tradeoffs.',
                'Very high financial burden relative to available institutional resources.',
            ],
        ];
        $expected = $criterion->code === 'COST' ? 'cost' : 'benefit';
        if (isset($defaults[$criterion->code]) && $criterion->direction === $expected) {
            return ['source' => 'suggested', 'label' => 'Suggested criterion guide — review for your institution', 'levels' => array_combine(range(1, 5), $defaults[$criterion->code])];
        }
        $levels = $criterion->direction === 'cost' ? [
            'Lowest value or burden; strongest upgrade preference.',
            'Low value or burden; strong upgrade preference.',
            'Moderate value or burden; moderate upgrade preference.',
            'High value or burden; weak upgrade preference.',
            'Highest value or burden; weakest upgrade preference.',
        ] : [
            'Very low upgrade need for this criterion.',
            'Low upgrade need for this criterion.',
            'Moderate upgrade need for this criterion.',
            'High upgrade need for this criterion.',
            'Critical upgrade need for this criterion.',
        ];

        return ['source' => 'general', 'label' => 'General scale — ask an administrator to define criterion-specific examples', 'levels' => array_combine(range(1, 5), $levels)];
    }
}
