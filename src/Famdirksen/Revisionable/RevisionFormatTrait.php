<?php

namespace Famdirksen\Revisionable;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Arr;

/**
 * Class RevisionableTrait
 * @package Famdirksen\Revisionable
 */
trait RevisionFormatTrait
{
    use SystemUserTrait;

    private function formatRevision($key, $oldValue, $newValue) {
        $revision = [
            'revisionable_type' => $this->getMorphClass(),
            'revisionable_id' => $this->getKey(),
            'key' => $key,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'user_id' => null,
            'ip' => $this->getRequestIp(),
            'created_at' => new \DateTime(),
            'updated_at' => new \DateTime(),
        ];

        $systemUser = $this->getSystemUser();

        if(is_array($systemUser)) {
            if(isset($systemUser['type']) && ! isset($systemUser['default_type'])) {
                $revision['user_type'] = $systemUser['type'];
            }

            if(isset($systemUser['id'])) {
                $revision['user_id'] = $systemUser['id'];
            }
        }

        return $revision;
    }

    /**
     * Get the IP from where the request came from
     *
     * @return null|string
     */
    public function getRequestIp()
    {
        if (! empty(Request::ip())) {
            return Request::ip();
        }

        return null;
    }
}
