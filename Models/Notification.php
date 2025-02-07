<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'title',
        'message',
        'read',
    ];

    public function addNotifications($userCategory)
    {
        $limitations = $userCategory->limitation;
        $manual_spending = $userCategory->manual_spending;

        $percent50 = $limitations * (50 / 100);
        $percent75 = $limitations * (75 / 100);
        $percent90 = $limitations * (90 / 100);
        $percent100 = $limitations * (100 / 100);
        // dd($percent50, $percent75,$percent90 ,$percent100);

        $store = 0;
        $category_name = $userCategory->category_name;
        if ($percent50 <= $manual_spending && $percent75 > $manual_spending && $percent90 > $manual_spending && $percent100 > $manual_spending) {
            $data = config("NotificationMessage.fifty_percent");
            $store = 1;
        } elseif ($percent50 < $manual_spending && $percent75 <= $manual_spending && $percent90 > $manual_spending && $percent100 >$manual_spending) {
            $data = config("NotificationMessage.seventy_five_percent");
            $store = 1;
        } elseif ($percent50 < $manual_spending && $percent75 < $manual_spending && $percent90 <= $manual_spending && $percent100 > $manual_spending) {
            $data = config("NotificationMessage.ninety_percent");
            $store = 1;
        } elseif ($percent50 < $manual_spending && $percent75 < $manual_spending && $percent90 < $manual_spending && $percent100 <= $manual_spending) {
            $data = config("NotificationMessage.hundred_percent");
            $store = 1;
        }
        if ($store == 1) {
            self::storeData($userCategory->user_id, $data, $category_name);
        }
    }

    public function storeData($user_id, $data, $category_name)
    {
        Notification::create([
            'user_id' => $user_id,
            'title' => $category_name . ' ' . $data['title'],
            'message' => $category_name . ' ' . $data['message'],
        ]);
    }
}
