<?php

namespace App\Http\Controllers;

use App\Models\Survey;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\File;
use App\Http\Resources\SurveyResource;
use App\Http\Requests\StoreSurveyRequest;
use Illuminate\Support\Facades\Validator;
use App\Http\Requests\UpdateSurveyRequest;
use App\Models\SurveyQuestion;
use Illuminate\Support\Arr;

class SurveyController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        return SurveyResource::collection(Survey::where('user_id', $user->id)->paginate());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreSurveyRequest $request)
    {
        $data = $request->validated();

        // check if image was given and save on local file
        if (isset($data['image'])) {
            $relativePath = $this->saveImage($data['image']);
            $data['image'] = $relativePath;
        }
        $survey = Survey::create($data);

        // create new questions
        foreach ($data['questions'] as $question) {
            $question['survey_id'] = $survey->id;
            $this->createQuestion($question);
        }
        return new SurveyResource($survey);
    }

    /**
     * Display the specified resource.
     */
    public function show(Survey $survey, Request $request)
    {
        $user = $request->user();
        if ($user->id !== $survey->user_id) {
            return abort(403, 'Unauthorization action.');
        }
        return new SurveyResource($survey);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateSurveyRequest $request, Survey $survey)
    {
        $data = $request->validated();

        if (isset($data['image'])) {
            $relativePath = $this->saveImage($data['image']);
            $data['image'] = $relativePath;

            // حذف الصورة القديمة إن وجدت
            if ($survey->image) {
                $absolutePath = public_path($survey->image);
                if (File::exists($absolutePath)) {
                    File::delete($absolutePath);
                }
            }
        }

        $survey->update($data);

        // get ids as plain array of existing questions
        $existingIds = $survey->questions()->pluck('id')->toArray();
        // get ids plain array of new questions
        $newIds = Arr::pluck($data['questions'],'id');
        // find question to delete
        $toDelete= array_diff($existingIds,$newIds);
        // find questions to add
        $toAdd= array_diff($newIds,$existingIds);
        // delete questions by $toDelete array
        SurveyQuestion::destroy($toDelete);
        // create new questions
        foreach ($data['questions'] as $question)
        {
            if(in_array($question['id'], $toAdd)){
                $question['syrvey_id']= $survey->id;
                $this->createQuestion($question);

            }
        }
        $questionMap= collect($data['questions'])->keyBy('id');
        // update existing questions
        foreach ($survey->questions as $question){
            if(isset($questionMap[$question->id])){
                $this->updateQuestion($question, $questionMap[$question->id]);
            }
        }
        return new SurveyResource($survey);
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Survey $survey, Request $request)
    {
        $user = $request->user();
        if ($user->id !== $survey->user_id) {
            return abort(403, 'Unauthorization action.');
        }
        $survey->delete();

        if ($survey->image) {
            $absolutePath = public_path($survey->image);

            if (File::exists($absolutePath)) {
                File::delete($absolutePath);
            }
        }
        return response('', 204);
    }

    private function saveImage($image)
    {
        //check if umage is valid base64 string
        if (preg_match("/^data:image\/(\w+);base64,/", $image, $type)) {
            //take out the base 64 encoded text without mime type
            $image = substr($image, strpos($image, ',') + 1);
            //get file extension
            $type = strtolower($type[1]); //jpg, png, ..

            //check if file is on image
            if (!in_array($type, ['jpg', 'jpge', 'png', 'gif'])) {
                throw new \Exception('invalid image type');
            }
            $image = str_replace(' ', '+', $image);
            $image = base64_decode($image);

            if ($image == false) {

                throw new \Exception('base64_decode failed');
            }
        } else {
            throw new \Exception('did not match data URL with image data');
        }

        $dir = 'images/';
        $file = Str::random() . '.' . $type;
        $absolutePath = public_path($dir);
        $relativePath = $dir . $file;
        if (! File::exists($absolutePath)) {
            File::makeDirectory($absolutePath, 0755, true);
        }
        file_put_contents($relativePath, $image);
        return $relativePath;
    }

    private function createQuestion($data){
        $data['data'] = json_encode($data['data']);
        $validator = Validator::make($data,[
            'question' => 'required|string',
            'type'=>['required', Rule::in([
                Survey::TYPE_TEXT,
                Survey::TYPE_TEXTAREA,
                Survey::TYPE_SELECT,
                Survey::TYPE_RADIO,
                Survey::TYPE_CHECKBOX,
            ])],
            'description'=>'nullable|string',
            'data'=>'present',
            'survey_id'=>'exists:surveys,id',
        ]);
        return SurveyQuestion::create($validator->validated());

    }

    private function updateQuestion(SurveyQuestion $question,$data){
        if(is_array($data['data'])){
            $data['data']= json_encode($data['data']);
        }
        $data['data'] = json_encode($data['data']);
        $validator = Validator::make($data,[
            'id'=>'exists:survey_questions,id',
            'question' => 'required|string',
            'type'=>['required', Rule::in([
                Survey::TYPE_TEXT,
                Survey::TYPE_TEXTAREA,
                Survey::TYPE_SELECT,
                Survey::TYPE_RADIO,
                Survey::TYPE_CHECKBOX,
            ])],
            'description'=>'nullable|string',
            'data'=>'present',
        ]);
        return $question->update($validator->validated());

    }
}
