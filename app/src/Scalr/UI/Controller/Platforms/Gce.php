<?php

use Scalr\Modules\PlatformFactory;
use Scalr\Modules\Platforms\GoogleCE\GoogleCEPlatformModule;
use Scalr\Model\Entity;

class Scalr_UI_Controller_Platforms_Gce extends Scalr_UI_Controller
{
    public function xGetFarmRoleStaticIpsAction($region, $cloudLocation, $farmRoleId) {
        $p = PlatformFactory::NewPlatform(SERVER_PLATFORMS::GCE);
        $gceClient = $p->getClient($this->environment);
        $projectId = $this->environment->cloudCredentials(SERVER_PLATFORMS::GCE)->properties[Entity\CloudCredentialsProperty::GCE_PROJECT_ID];

        $map = array();

        if ($farmRoleId) {
            $dbFarmRole = DBFarmRole::LoadByID($farmRoleId);
            $this->user->getPermissions()->validate($dbFarmRole);

            $maxInstances = $dbFarmRole->GetSetting(Entity\FarmRoleSetting::SCALING_MAX_INSTANCES);
            for ($i = 1; $i <= $maxInstances; $i++) {
                $map[] = array('serverIndex' => $i);
            }

            $servers = $dbFarmRole->GetServersByFilter();
            for ($i = 0; $i < count($servers); $i++) {
                if ($servers[$i]->status != SERVER_STATUS::TERMINATED && $servers[$i]->index) {
                    $map[$servers[$i]->index - 1]['serverIndex'] = $servers[$i]->index;
                    $map[$servers[$i]->index - 1]['serverId'] = $servers[$i]->serverId;
                    $map[$servers[$i]->index - 1]['remoteIp'] = $servers[$i]->remoteIp;
                    $map[$servers[$i]->index - 1]['instanceId'] = $servers[$i]->GetProperty(GCE_SERVER_PROPERTIES::SERVER_NAME);
                }
            }

            $ips = $this->db->GetAll('SELECT ipaddress, instance_index FROM elastic_ips WHERE farm_roleid = ?', array($dbFarmRole->ID));
            for ($i = 0; $i < count($ips); $i++) {
                $map[$ips[$i]['instance_index'] - 1]['elasticIp'] = $ips[$i]['ipaddress'];
            }
        }

        $response = $gceClient->addresses->listAddresses($projectId, $region);

        $ips = array();
        /* @var $ip \Google_Service_Compute_Address */
        foreach ($response as $ip) {
            $itm = array(
                'ipAddress'  => $ip->getAddress(),
                'description' => $ip->getDescription()
            );
            if ($ip->status == 'IN_USE')
                $itm['instanceId'] = substr(strrchr($ip->users[0], "/"), 1);

            $info = $this->db->GetRow("
                SELECT * FROM elastic_ips WHERE ipaddress = ? LIMIT 1
            ", array($itm['ipAddress']));

            if ($info) {
                try {
                    if ($info['server_id'] == $itm['instanceId']) {
                        for ($i = 0; $i < count($map); $i++) {
                            if ($map[$i]['elasticIp'] == $itm['ipAddress'])
                                $map[$i]['warningInstanceIdDoesntMatch'] = true;
                        }
                    }

                    $farmRole = DBFarmRole::LoadByID($info['farm_roleid']);
                    $this->user->getPermissions()->validate($farmRole);

                    $itm['roleName'] = $farmRole->Alias;
                    $itm['farmName'] = $farmRole->GetFarmObject()->Name;
                    $itm['serverIndex'] = $info['instance_index'];
                } catch (Exception $e) {}
            }

            //TODO: Mark Router EIP ad USED

            $ips[] = $itm;
        }

        $this->response->data(['data' => ['staticIps' => ['map' => $map, 'ips' => $ips]]]);
    }

    public function xGetOptionsAction()
    {
        $p = PlatformFactory::NewPlatform(SERVER_PLATFORMS::GCE);
        $gceClient = $p->getClient($this->environment);
        $projectId = $this->environment->cloudCredentials(SERVER_PLATFORMS::GCE)->properties[Entity\CloudCredentialsProperty::GCE_PROJECT_ID];

        $data['zones'] = array();
        $zones = $gceClient->zones->listZones($projectId);
        foreach ($zones->items as $item) {
            
            if ($item->deprecated)
                $item->description .= " (Deprecated)";
            
            $data['zones'][] = array(
                'name' => $item->name,
                'description' => $item->description,
                'state' => $item->status
            );
        }

        $data['regions'] = array();
        $regions = $gceClient->regions->listRegions($projectId);
        foreach ($regions->items as $item) {
            /* @var $item \Google_Service_Compute_Region */

            $zones = array();
            if (!empty($item->zones)) {
                foreach ($item->zones as $zone) {
                    $name = $p->getObjectName($zone);
                    $zones[$name] = substr($name, strrpos($name, "-")+1);
                }
            }

            $data['regions'][] = array(
                'name' => $item->name,
                'description' => $item->description,
                'state' => $item->status,
                'deprecated' => $item->getDeprecated()->state,
                'zones' => $zones
            );
        }

        $data['networks'] = array();
        $networks = $gceClient->networks->listNetworks($projectId);
        foreach ($networks->items as $item) {

            $description = ($item->description != '') ? "{$item->name} - {$item->description} ({$item->IPv4Range})" : "{$item->name} ({$item->IPv4Range})";

            $data['networks'][] = array(
                'name' => $item->name,
                'description' => $description
            );
        }
        
        $diskTypes = $gceClient->diskTypes->listDiskTypes($projectId, $data['zones'][0]['name']);
        foreach ($diskTypes as $diskType) {
            /* @var $diskType \Google_Service_Compute_DiskType */
            $data['diskTypes'][] = [
                'name'        => $diskType->name,
                'description' => $diskType->description,
                'defaultSize' => $diskType->defaultDiskSizeGb
            ];
        }

        $this->response->data(array('data' => $data));
    }

    
    // NOT USED. Should we remove this method?
    public function xGetMachineTypesAction()
    {
        $p = PlatformFactory::NewPlatform(SERVER_PLATFORMS::GCE);

        $data['types'] = [];
        $data['diskTypes'] = [];
        $data['dbTypes'] = [];

        $items = $p->getInstanceTypes($this->environment, $this->getParam('cloudLocation'), true);

        foreach ($items as $item) {
            $data['types'][] = [
                'name'        => $item['name'],
                'description' => $item['name'] . " (" . $item['description'] . ")"
            ];
        }
        

        $this->response->data(['data' => $data]);
    }

}
