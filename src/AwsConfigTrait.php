<?php
/**
 * BEdita, API-first content management framework
 * Copyright 2022 Atlas Srl, Chialab Srl
 *
 * This file is part of BEdita: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * See LICENSE.LGPL or <http://gnu.org/licenses/lgpl-3.0.html> for more details.
 */

namespace BEdita\AWS;

/**
 * Trait to share common logic across several AWS client wrappers.
 *
 * @package BEdita\AWS
 */
trait AwsConfigTrait
{
    /**
     * Reformat configuration preparing it for
     *
     * @param array $config Configuration.
     * @return array
     */
    protected function reformatConfig(array $config): array
    {
        if (!empty($config['username']) && !empty($config['password'])) {
            $config['credentials'] = [
                'key' => $config['username'],
                'secret' => $config['password'],
            ];
        }

        return $config;
    }
}
