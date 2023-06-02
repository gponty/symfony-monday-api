<?php declare(strict_types=1);

namespace Gponty\MondayBundle;

class MondayApi
{
    public function __construct(private readonly string $mondayApiKey)
    {
    }

    public function request(string $query): bool|array
    {
        $apiUrl = 'https://api.monday.com/v2';
        $headers = [
            'Content-Type: application/json',
            'User-Agent: Github.com/symfony-monday-api',
            'Authorization: '.$this->mondayApiKey,
        ];

        $data = \file_get_contents($apiUrl, false, \stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $headers,
                'content' => \json_encode(['query' => $query]),
            ],
        ]));

        if (!$data) {
            return false;
        }

        $json = \json_decode($data, true);
        if (!\is_array($json)) {
            return false;
        }

        if (isset($json['data'])) {
            return $json['data'];
        } elseif (isset($json['errors']) && \is_array($json['errors'])) {
            return $json['errors'];
        }

        return false;
    }

    /**
     * Create new group if not exists.
     */
    public function createGroup(int $tableau, string $titreGroupe): int|bool
    {
        // check if group already exists
        $query = '{
                      boards(ids: '.$tableau.') {
                        groups {
                          id
                          title
                        }
                      }
                    }
                ';

        $responseContent = $this->request($query);
        if (false === $responseContent) {
            return false;
        }
        if (!isset($responseContent['boards'][0]['groups']) || !\is_array($responseContent['boards'][0]['groups'])) {
            return false;
        }

        $idGroupe = null;
        foreach ($responseContent['boards'][0]['groups'] as $group) {
            if ($group['title'] === $titreGroupe) {
                $idGroupe = $group['id'];
            }
        }

        // create group if not exists
        if (null === $idGroupe) {
            $query = 'mutation {
                          create_group (board_id: '.$tableau.', group_name: "'.$titreGroupe.'") {
                            id
                          }
                        }
                        ';
            $responseContent = $this->request($query);
            if (false === $responseContent) {
                return false;
            }
            if (!isset($responseContent['create_group']['id'])) {
                return false;
            }
            $idGroupe = $responseContent['create_group']['id'];
        }

        return $idGroupe;
    }

    public function createItem(int $tableau, string $groupeId, string $nomItem, array $values): int|bool
    {
        // On insere ou update dans Monday
        // On recuperer tous les items et groupes pour checker que l'item existe ou pas
        $query = '{
                  boards(ids: '.$tableau.') {
                    id
                    name
                    groups(ids: '.$groupeId.') {
                      id
                      title
                      items {id name}
                    }
                  }
                }
            ';
        $responseContent = $this->request($query);
        if (false === $responseContent) {
            return false;
        }
        if (!isset($responseContent['boards'][0]['groups'][0]['items']) || !\is_array($responseContent['boards'][0]['groups'][0]['items'])) {
            return false;
        }
        $items = $responseContent['boards'][0]['groups'][0]['items'];

        // On créé les domaines qui n'existent pas
        // et on met à jour ceux qui existent
        $trouve = false;
        $itemId = 0;

        foreach ($items as $item) {
            if ($item['name'] === $nomItem) {
                $trouve = true;
                $itemId = $item['id'];
                break;
            }
        }

        if (!$trouve) {
            $query = 'mutation {
                              create_item(board_id: '.$tableau.', group_id: "'.$groupeId.'", item_name: "'.$nomItem.'") {
                                id
                              }
                            }
                        ';
            $data = $this->request($query);
            if (false === $data) {
                return false;
            }
            if (!isset($data['create_item']['id'])) {
                return false;
            }
            $itemId = $data['create_item']['id'];
        }

        // On met à jour
        $json = $this->encodeValueMutation($values);
        if (false === $json) {
            return false;
        }

        $query = 'mutation {
                          change_multiple_column_values(item_id: '.$itemId.', board_id: '.$tableau.', column_values: "'.$json.'",create_labels_if_missing: true) {
                            id
                          }
                        }
                        ';
        $this->request($query);

        return $itemId;
    }

    public function createSubItem(int $tableau, string $groupeId, string $itemId, string $nomSubItem, array $values): int|bool
    {
        // On insere ou update dans Monday
        // On recuperer tous les subitems et groupes pour checker que le subitem existe ou pas
        $query = '{
                    items (ids: ['.$itemId.']) {
                        id
                        name
                        subitems {
                            id
                            name
                            column_values {
                                id
                                value
                                text
                              }
                              board{
                                    id
                                }
                        }
                    }
                }
            ';
        $responseContent = $this->request($query);
        if (false === $responseContent) {
            return false;
        }
        if (!isset($responseContent['items'][0]['subitems']) || !\is_array($responseContent['items'][0]['subitems'])) {
            return false;
        }
        $subItems = $responseContent['items'][0]['subitems'];

        // On créé les domaines qui n'existent pas
        // et on met à jour ceux qui existent
        $trouve = false;
        $subItemId = 0;
        foreach ($subItems as $subItem) {
            if ($subItem['name'] === $nomSubItem) {
                $trouve = true;
                $subItemId = $subItem['id'];
                // Les subitems se trouvent dans un autre boardid
                $tableau = $subItem['board']['id'];
                break;
            }
        }

        if (!$trouve) {
            $query = 'mutation {
                              create_subitem(parent_item_id: '.$itemId.', item_name: "'.$nomSubItem.'") {
                                id
                                board{
                                    id
                                }
                              }
                            }
                        ';
            $data = $this->request($query);
            if (false === $data) {
                return false;
            }
            if (!isset($data['create_subitem']['id']) || !isset($data['create_subitem']['board']['id'])) {
                return false;
            }

            $subItemId = $data['create_subitem']['id'];
            $tableau = $data['create_subitem']['board']['id'];
        }

        // On met à jour
        $json = $this->encodeValueMutation($values);
        if (false === $json) {
            return false;
        }

        $query = 'mutation {
                          change_multiple_column_values( board_id: '.$tableau.', item_id: '.$subItemId.', column_values: "'.$json.'",create_labels_if_missing: true) {
                            id
                          }
                        }
                        ';
        $this->request($query);

        return $subItemId;
    }

    private function encodeValueMutation(array $values): bool|string
    {
        $encodedValue = \json_encode($values);
        if (!$encodedValue) {
            return false;
        }

        return \addslashes($encodedValue);
    }
}
