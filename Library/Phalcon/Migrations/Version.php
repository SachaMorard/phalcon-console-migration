<?php


namespace Phalcon\Migrations;

/**
 * Class Version
 * @package Phalcon\Migration
 */
class Version
{

    /**
     * @param $versions
     * @return array
     */
    public static function sortAsc($versions)
    {
        $sortData = array();
        foreach ($versions as $version) {
            $sortData[$version] = $version;
        }
        ksort($sortData);

        return array_values($sortData);
    }

    /**
     * @param $versions
     * @return array
     */
    public static function sortDesc($versions)
    {
        $sortData = array();
        foreach ($versions as $version) {
            $sortData[$version] = $version;
        }
        krsort($sortData);

        return array_values($sortData);
    }

    /**
     * @param $versions
     * @return mixed|null
     */
    public static function maximum($versions)
    {
        if (count($versions) == 0) {
            return null;
        } else {
            $versions = self::sortDesc($versions);

            return $versions[0];
        }
    }

    /**
     * @param $initialVersion
     * @param $finalVersion
     * @param $versions
     * @return array
     */
    public static function between($initialVersion, $finalVersion, $versions)
    {
        $versions = self::sortAsc($versions);
        $direction = 'up';

        if ($initialVersion > $finalVersion) {
            $versions = self::sortDesc($versions);
            list($initialVersion, $finalVersion) = array($finalVersion, $initialVersion);
            $direction = 'down';
        }
        $betweenVersions = array();
        foreach ($versions as $version) {
            /**
             * @var $version Version
             */
            if (($version > $initialVersion) && ($version <= $finalVersion)) {
                $betweenVersions[] = array('version' => $version, 'direction' => $direction);
            }
        }
        return $betweenVersions ;
    }
}
