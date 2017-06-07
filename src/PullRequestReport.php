<?php

/*
 * Copyright 2014 Daniel James <daniel@calamity.org.uk>.
 *
 * Distributed under the Boost Software License, Version 1.0. (See accompanying
 * file LICENSE_1_0.txt or copy at http://www.boost.org/LICENSE_1_0.txt)
 */

use Nette\Object;
use BoostTasks\Db;

class PullRequestReport extends Object {
    static function update($all = false) {
        $report = new PullRequestReport;
        if ($all) {
            $report->fullUpdate();
        }
        else {
            $report->updateFromQueue();
        }
        $report->write();
    }

    public function webhookDatabase() {
        // TODO: This shouldn't be hard-coded.
        static $database = null;
        if (!$database) {
            $database = Db::create('sqlite:'.BOOST_TASKS_ROOT.'/var/webhook-data/cache.db');
        }
        return $database;
    }

    public function fullUpdate() {
        // Get queue position before downloading pull requests.
        $last_event_id = $this->webhookDatabase()->getCell('SELECT max(`id`) FROM pull_request_event');

        // Download pull requests.
        $pull_requests = Array();
        foreach (EvilGlobals::githubCache()->iterate('/orgs/boostorg/repos') as $repo) {
            foreach (EvilGlobals::githubCache()->iterate("/repos/{$repo->full_name}/pulls") as $pull) {
                $data = new \stdClass();
                $data->id = $pull->id;
                $data->repo_full_name = $repo->full_name;
                $data->pull_request_number = $pull->number;
                $data->pull_request_url = $pull->html_url;
                $data->pull_request_title = $pull->title;
                $data->pull_request_created_at = $pull->created_at;
                $data->pull_request_updated_at = $pull->updated_at;
                $pull_requests[$data->id] = $data;
            }
        }

        $db = EvilGlobals::database();
        $db->begin();
        $existing_pull_requests = array();

        foreach($db->find('pull_request') as $row) {
            $existing_pull_requests[$row->id] = $row;
        }

        foreach ($pull_requests as $id => $data) {
            if (array_key_exists($id, $existing_pull_requests)) {
                $record = $existing_pull_requests[$id];
                unset($existing_pull_requests[$id]);
                assert($record->repo_full_name === $data->repo_full_name);
                assert($record->pull_request_number == $data->pull_request_number);
                assert($record->pull_request_url === $data->pull_request_url);
                assert($record->pull_request_created_at === $data->pull_request_created_at);
            }
            else {
                $record = $db->dispense('pull_request');
                $record->id = $data->id;
                $record->repo_full_name = $data->repo_full_name;
                $record->pull_request_number = $data->pull_request_number;
                $record->pull_request_url = $data->pull_request_url;
                $record->pull_request_created_at = $data->pull_request_created_at;
            }
            $record->pull_request_title = $data->pull_request_title;
            $record->pull_request_updated_at = $data->pull_request_updated_at;
            $record->store();
        }

        foreach($existing_pull_requests as $record) {
            $record->trash();
        }

        $queue = $this->loadQueue($db);
        $queue->last_github_id = $last_event_id;
        $queue->store();

        $db->commit();
    }

    public function updateFromQueue() {
        $webhook_database = $this->webhookDatabase();
        $db = EvilGlobals::database();
        $db->begin();
        $queue = $this->loadQueue($db);
        foreach($webhook_database->getAll('SELECT * FROM pull_request_event WHERE id > ? ORDER BY id', array($queue->last_github_id)) AS $row) {
            $record = $db->load('pull_request', $row['pull_request_id']);
            if ($record) {
                assert($record->repo_full_name === $row['repo_full_name']);
                assert($record->pull_request_number === $row['pull_request_number']);
                assert($record->pull_request_url === $row['pull_request_url']);
                assert($record->pull_request_created_at === $row['pull_request_created_at']);
            }

            switch($row['pull_request_state']) {
            case 'open':
                if (!$record) {
                    $record = $db->dispense('pull_request');
                    $record->id = $row['pull_request_id'];
                    $record->repo_full_name = $row['repo_full_name'];
                    $record->pull_request_number = $row['pull_request_number'];
                    $record->pull_request_url = $row['pull_request_url'];
                    $record->pull_request_created_at = $row['pull_request_created_at'];
                }
                $record->pull_request_title = $row['pull_request_title'];
                $record->pull_request_updated_at = $row['pull_request_updated_at'];
                $record->store();
                break;
            case 'closed':
                if ($record) {
                    $record->trash();
                }
                break;
            default:
                Log::Error("Unknown pull request state: {$row['pull_request_state']}");
            }

            $queue->last_github_id = $row['id'];
            $queue->store();
        }
        $db->commit();
    }

    public function loadQueue($db) {
        $queue = $db->findOne('queue', 'name = ? AND type = ?',
            array('pull_request','PullRequestWebhook'));
        if (!$queue) {
            $queue = $db->dispense('queue');
            $queue->name = 'pull_request';
            $queue->type = 'PullRequestWebhook';
            $queue->last_github_id = 0;
        }
        return $queue;
    }

    public function write() {
        // Date parsing:
        // echo date("r\n", strtotime("2014-01-27T05:26:41Z"));

        $pull_requests = Array();
        foreach (EvilGlobals::database()->getAll('SELECT * FROM pull_request') as $row) {
            $data = new \stdClass();
            $data->id = $row['id'];
            $data->html_url = $row['pull_request_url'];
            $data->title = $row['pull_request_title'];
            $data->created_at = $row['pull_request_created_at'];
            $data->updated_at = $row['pull_request_updated_at'];
            $pull_requests[$row['repo_full_name']][] = $data;
        }
        ksort($pull_requests);

        $json_data = Array(
            'last_updated' => date('c'),
            'pull_requests' => $pull_requests,
        );

        $json = json_encode($json_data);

        if (\EvilGlobals::settings('website-data')) {
            file_put_contents(\EvilGlobals::settings('website-data').'/pull-requests.json', $json);
        }
        else {
            echo $json;
        }
    }
}
