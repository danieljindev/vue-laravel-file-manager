<?php

namespace App;

use ByteUnits\Metric;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;
use TeamTNT\TNTSearch\Indexer\TNTIndexer;
use \Illuminate\Database\Eloquent\SoftDeletes;

/**
 * App\FileManagerFile
 *
 * @property int $id
 * @property int|null $user_id
 * @property int $unique_id
 * @property int $folder_id
 * @property string $thumbnail
 * @property string|null $name
 * @property string|null $basename
 * @property string|null $mimetype
 * @property string $filesize
 * @property string|null $type
 * @property string $user_scope
 * @property string $deleted_at
 * @property string $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\FileManagerFolder|null $folder
 * @property-read string $file_url
 * @property-read \App\FileManagerFolder $parent
 * @property-read \App\Share|null $shared
 * @method static \Illuminate\Database\Eloquent\Builder|\App\FileManagerFile newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\FileManagerFile newQuery()
 * @method static \Illuminate\Database\Query\Builder|\App\FileManagerFile onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\FileManagerFile query()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\FileManagerFile whereBasename($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\FileManagerFile whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\FileManagerFile whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\FileManagerFile whereFilesize($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\FileManagerFile whereFolderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\FileManagerFile whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\FileManagerFile whereMimetype($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\FileManagerFile whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\FileManagerFile whereThumbnail($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\FileManagerFile whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\FileManagerFile whereUniqueId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\FileManagerFile whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\FileManagerFile whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\FileManagerFile whereUserScope($value)
 * @method static \Illuminate\Database\Query\Builder|\App\FileManagerFile withTrashed()
 * @method static \Illuminate\Database\Query\Builder|\App\FileManagerFile withoutTrashed()
 * @mixin \Eloquent
 */
class FileManagerFile extends Model
{
    use Searchable, SoftDeletes;

    public $public_access = null;

    protected $guarded = [
        'id'
    ];

    protected $appends = [
        'file_url'
    ];

    /**
     * Set routes with public access
     *
     * @param $token
     */
    public function setPublicUrl($token)
    {
        $this->public_access = $token;
    }

    /**
     * Format created at date
     *
     * @return string
     */
    public function getCreatedAtAttribute()
    {
        return format_date($this->attributes['created_at'], __('vuefilemanager.time'));
    }

    /**
     * Form\a\t created at date reformat
     *
     * @return string
     */
    public function getDeletedAtAttribute()
    {
        if (!$this->attributes['deleted_at']) return null;

        return format_date($this->attributes['deleted_at'], __('vuefilemanager.time'));
    }

    /**
     * Format fileSize
     *
     * @return string
     */
    public function getFilesizeAttribute()
    {
        return Metric::bytes($this->attributes['filesize'])->format();
    }

    /**
     * Format thumbnail url
     *
     * @return string
     */
    public function getThumbnailAttribute()
    {
        // Get thumbnail from s3
        if ($this->attributes['thumbnail'] && is_storage_driver(['s3', 'spaces'])) {

            return Storage::temporaryUrl('file-manager/' . $this->attributes['thumbnail'], now()->addDay());
        }

        // Get thumbnail from local storage
        if ($this->attributes['thumbnail'] && is_storage_driver('local')) {

            // Thumbnail route
            $route = route('thumbnail', ['name' => $this->attributes['thumbnail']]);

            if ($this->public_access) {
                return $route . '/public/' . $this->public_access;
            }

            return $route;
        }

        return null;
    }

    /**
     * Format file url
     *
     * @return string
     */
    public function getFileUrlAttribute()
    {
        // Get file from s3
        if (is_storage_driver(['s3', 'spaces'])) {

            $header = [
                "ResponseAcceptRanges"       => "bytes",
                "ResponseContentType"        => $this->attributes['mimetype'],
                "ResponseContentLength"      => $this->attributes['filesize'],
                "ResponseContentRange"       => "bytes 0-600/" . $this->attributes['filesize'],
                'ResponseContentDisposition' => 'attachment; filename=' . $this->attributes['name'] . '.' . $this->attributes['mimetype'],
            ];

            return Storage::temporaryUrl('file-manager/' . $this->attributes['basename'], now()->addDay(), $header);
        }

        // Get thumbnail from local storage
        if (is_storage_driver('local')) {

            $route = route('file', ['name' => $this->attributes['basename']]);

            if ($this->public_access) {
                return $route . '/public/' . $this->public_access;
            }

            return $route;
        }
    }

    /**
     * Index file
     *
     * @return array
     */
    public function toSearchableArray()
    {
        $array = $this->toArray();
        $name = Str::slug($array['name'], ' ');

        return [
            'id'         => $this->id,
            'name'       => $name,
            'nameNgrams' => utf8_encode((new TNTIndexer)->buildTrigrams(implode(', ', [$name]))),
        ];
    }

    /**
     * Get parent
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function parent()
    {
        return $this->belongsTo('App\FileManagerFolder', 'folder_id', 'unique_id');
    }

    /**
     * Get folder
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function folder()
    {
        return $this->hasOne('App\FileManagerFolder', 'unique_id', 'folder_id');
    }

    /**
     * Get sharing attributes
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function shared()
    {
        return $this->hasOne('App\Share', 'item_id', 'unique_id');
    }
}
