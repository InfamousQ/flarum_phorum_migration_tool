<?php

namespace InfamousQ\FlarumPhorumMigrationTool\Model;

use Carbon\Carbon;

/**
 * HistoricCommentPost
 * Similar as Flarum\Post\CommentPost but overwrites the time of posting.
 */
class HistoricCommentPost extends \Flarum\Post\CommentPost {

	/**
	* Create a new instance in reply to a discussion.
	*
	* @param int $discussionId
	* @param string $content
	* @param int $userId
	* @param string $ipAddress
	* @param string $timestamp Unix timestamp
	* @return HistoricCommentPost
	*/
	public static function replyAtTime($discussionId, $content, $userId, $ipAddress, $timestamp) {
		$post = new static;

		$post->created_at = Carbon::createFromTimestamp($timestamp);
		$post->discussion_id = $discussionId;
		$post->user_id = $userId;
		$post->type = static::$type;
		$post->ip_address = $ipAddress;

		// Set content last, as the parsing may rely on other post attributes.
		$post->content = $content;

		$post->raise(new \Flarum\Post\Event\Posted($post));

		return $post;
	}

}