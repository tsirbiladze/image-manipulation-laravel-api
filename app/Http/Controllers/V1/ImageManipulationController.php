<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImageResizeRequest;
use App\Http\Resources\V1\ImageManipulationResource;
use App\Models\Album;
use App\Models\ImageManipulation;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

class ImageManipulationController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function index(Request $request)
    {
        return ImageManipulationResource::collection(ImageManipulation::where('user_id', $request->user()->id)->paginate());
    }

    public function getByAlbum(Album $album, Request $request)
    {
        if($album->user_id != $request->user()->id) {
            return abort(403, 'Unauthorized');
        }

        $where = [
            'album_id' => $album->id,
        ];
        return ImageManipulationResource::collection(ImageManipulation::where($album)->paginate());
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\ImageResizeRequest  $request
     * @return ImageManipulationResource
     */
    public function resize(ImageResizeRequest $request)
    {
        $all = $request->all();

        /** @var UploadedFile/string $image*/
        $image = $all['image'];
        unset($all['image']);
        $data = [
            'type' => imageManipulation::TYPE_RESIZE,
            'data' => json_encode($all),
            'user_id' => $request->user()->id,
        ];

        if(isset($all['album_id'])) {
            $album = Album::find($all['album_id']);

            if($album->user_id != $request->user()->id) {
                return abort(403, 'Unauthorized');
            }

            $data['album_id'] = $all['album_id'];
        }

        $dir = 'images/'.Str::random().'/';
        $absolutePath = public_path($dir);
        if(!File::exists($absolutePath)) {
            File::makeDirectory($absolutePath, 0755, true);
        }

        if($image instanceof UploadedFile) {
            $data['name'] = $image->getClientOriginalName();
            $fileName = pathInfo($data['name'], PATHINFO_FILENAME);
            $extension = $image->getClientOriginalExtension();
            $originalPath = $absolutePath . $data['name'];
            $image->move($absolutePath, $data['name']);

        } else {
            $data['name'] = pathinfo($image, PATHINFO_BASENAME);
            $fileName = pathinfo($image, PATHINFO_FILENAME);
            $extension = pathinfo($image, PATHINFO_EXTENSION);
            $originalPath = $absolutePath . $data['name'];

            copy($image, $originalPath);
        }

        $data['path'] = $dir . $data['name'];
        $w = $all['w'];
        $h = $all['h'] ?? false;

        list($width, $height, $image) = $this->getWidthAndHeight($w, $h, $originalPath);

        $resizedFilename = $fileName.'-resized.'.$extension;
        $image->resize($width, $height)->save($absolutePath.$resizedFilename);

        $data['output_path'] = $dir.$resizedFilename;

        $imageManipulation = ImageManipulation::create($data);

        return new ImageManipulationResource($imageManipulation);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\ImageManipulation  $imageManipulation
     * @return ImageManipulationResource
     */
    public function show(ImageManipulation $image, Request $request)
    {
        if($image->user_id != $request->user()->id) {
            return abort(403, 'Unauthorized');
        }

        return new ImageManipulationResource($image);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\ImageManipulation  $imageManipulation
     * @return \Illuminate\Http\Response
     */
    public function destroy(ImageManipulation $image, Request $request)
    {
        if($image->user_id != $request->user()->id) {
            return abort(403, 'Unauthorized');
        }

        $image->delete();

        return response('', 204);
    }

    protected function getWidthAndHeight(mixed $w, mixed $h, string $originalPath)
    {
        $image = Image::make($originalPath);
        $originalWidth = $image->width();
        $originalHeight = $image->height();

        if(str_ends_with($w, '%')) {
            $ratioW = str_replace('%', '', $w);
            $ratioH = $h ? str_replace('%', '', $h) : $ratioW;

            $newWidth = $originalWidth * $ratioW / 100;
            $newHeight = $originalHeight * $ratioH / 100;
        } else {
            $newWidth = (float)$w;
            /**
             * $originalWidth - $newWidth
             * $originalHeight - $newHeight
             *
             * $newHeight = $originalHeight * $newWidth/$originalWidth
             */

            $newHeight = $h ? (float)$h : ($originalHeight * $newWidth/$originalWidth);
        }

        return [$newWidth, $newHeight, $image];
    }
}
