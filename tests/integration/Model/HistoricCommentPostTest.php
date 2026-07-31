<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Tests\integration\Model;

use Flarum\Discussion\Discussion;
use Flarum\User\User;
use InfamousQ\FlarumPhorumMigrationTool\Model\HistoricCommentPost;
use InfamousQ\FlarumPhorumMigrationTool\Tests\integration\TestCase;

class HistoricCommentPostTest extends TestCase
{
    /**
     * @test
     */
    public function it_backdates_the_post_to_the_given_timestamp_instead_of_now()
    {
        $user = User::register('Alice', 'alice@example.com', 'password');
        $user->save();

        $discussion = Discussion::start('Test discussion', $user);
        $discussion->save();

        $timestamp = 1600000000; // 2020-09-13T12:26:40Z

        $post = HistoricCommentPost::replyAtTime($discussion->id, 'Historic content', $user->id, '127.0.0.1', $timestamp);
        $post->save();

        $this->assertSame($timestamp, $post->created_at->getTimestamp());
        $this->assertSame($discussion->id, $post->discussion_id);
        $this->assertSame($user->id, $post->user_id);
        $this->assertSame('127.0.0.1', $post->ip_address);
    }
}
