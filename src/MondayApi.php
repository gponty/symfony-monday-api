<?php

namespace Gponty\MondayBundle;

class MondayApi
{
    public function __construct(
        private readonly string $mondayApiKey)
    {

    }

    public function showCoucou(){
        dump('coucou');
        dump($this->mondayApiKey);
    }

    public function makeQuery($query)
    {

        dump($this->mondayApiKey);

        $apiUrl = 'https://api.monday.com/v2';
        $headers = ['Content-Type: application/json', 'Authorization: '.$this->mondayApiKey];

        $data = file_get_contents($apiUrl, false, stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $headers,
                'content' => json_encode(['query' => $query]),
            ],
        ]));

        $retour = json_decode($data, true);
        // dump($retour);

        return $retour;
    }

    public function createGroupe($tableau, $titreGroupe)
    {
        // On regarde si le groupe existe
        $query = '{
                      boards(ids: '.$tableau.') {
                        groups {
                          id
                          title
                        }
                      }
                    }
                ';

        $responseContent = $this->makeMondayQuery($query);
        $idGroupe = null;
        foreach ($responseContent['data']['boards'][0]['groups'] as $group) {
            if ($group['title'] === $titreGroupe) {
                $idGroupe = $group['id'];
            }
        }

        // On créé le groupe si il n'existe pas
        if (null === $idGroupe) {
            $query = 'mutation {
                          create_group (board_id: '.$tableau.', group_name: "'.$titreGroupe.'") {
                            id
                          }
                        }
                        ';
            $responseContent = $this->makeMondayQuery($query);
            $idGroupe = $responseContent['data']['create_group']['id'];
        }

        return $idGroupe;
    }

    public function createItem(int $tableau, string $groupeId, string $nomItem, array $values)
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
        $responseContent = $this->makeMondayQuery($query);
        $items = $responseContent['data']['boards'][0]['groups'][0]['items'];

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
            $data = $this->makeMondayQuery($query);
            $itemId = $data['data']['create_item']['id'];
        }

        // On met à jour
        $json = addslashes(json_encode($values));

        $query = 'mutation {
                          change_multiple_column_values(item_id: '.$itemId.', board_id: '.$tableau.', column_values: "'.$json.'",create_labels_if_missing: true) {
                            id
                          }
                        }
                        ';
        $this->makeMondayQuery($query);

        return $itemId;
    }

    public function createSubItem(int $tableau, string $groupeId, string $itemId, string $nomSubItem, array $values)
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
        $responseContent = $this->makeMondayQuery($query);
        $subItems = $responseContent['data']['items'][0]['subitems'];

        // On créé les domaines qui n'existent pas
        // et on met à jour ceux qui existent
        $trouve = false;
        $subItemId = 0;
        if (null !== $subItems) {
            foreach ($subItems as $subItem) {
                if ($subItem['name'] === $nomSubItem) {
                    $trouve = true;
                    $subItemId = $subItem['id'];
                    // Les subitems se trouvent dans un autre boardid
                    $tableau = $subItem['board']['id'];
                    break;
                }
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
            $data = $this->makeMondayQuery($query);
            $subItemId = $data['data']['create_subitem']['id'];
            $tableau = $data['data']['create_subitem']['board']['id'];
        }

        // On met à jour
        $json = addslashes(json_encode($values));

        $query = 'mutation {
                          change_multiple_column_values( board_id: '.$tableau.', item_id: '.$subItemId.', column_values: "'.$json.'",create_labels_if_missing: true) {
                            id
                          }
                        }
                        ';
        $this->makeMondayQuery($query);

        return $subItemId;
    }
}
