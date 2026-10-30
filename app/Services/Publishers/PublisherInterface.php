<?php

namespace App\Services\Publishers;

use App\Models\Post;
use App\Models\PublishTarget;

interface PublisherInterface
{
    /**
     * เผยแพร่เนื้อหา
     *
     * @return array [ 'success' => bool, 'external_id' => string|null, 'external_url' => string|null, 'error' => string|null ]
     */
    public function publish(Post $post, PublishTarget $target): array;

    /**
     * อัปเดตเนื้อหาที่เคยเผยแพร่ไปแล้ว
     */
    public function update(Post $post, PublishTarget $target): array;

    /**
     * ลบเนื้อหา
     */
    public function delete(PublishTarget $target): bool;

    /**
     * ตรวจสอบว่าสามารถเชื่อมต่อได้หรือไม่
     */
    public function healthCheck(): bool;
}
