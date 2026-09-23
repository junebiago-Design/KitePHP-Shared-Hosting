<?php
namespace Core;

abstract class Controller
{
    protected $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    protected function view(string $view, array $data = [], ?string $layout = 'layouts/main', int $status = 200): Response
    {
        return Response::html(View::render($view, $data, $layout), $status);
    }

    protected function json($data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function redirect(string $to): Response
    {
        return Response::redirect($to);
    }

    /** Throws 403 unless the logged-in user has the permission. */
    protected function authorize(string $permission): void
    {
        if (!Auth::can($permission)) {
            throw new HttpException(403, 'You do not have permission to do that.');
        }
    }

    /** Validates request input. On failure throws ValidationException (handled by App). */
    protected function validate(array $rules): array
    {
        $errors = Validator::validate($this->request->all(), $rules);
        if ($errors) {
            throw new ValidationException($errors);
        }
        return $this->request->only(array_keys($rules));
    }
}
