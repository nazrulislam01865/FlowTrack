@props(['couriers' => []])

<select {{ $attributes->merge(['class' => 'ft-ms-courier-select']) }}>
    <option value="">Select courier</option>
    @foreach(collect($couriers) as $courier)
        <option value="{{ (int) ($courier['id'] ?? 0) }}">{{ $courier['name'] ?? '' }}</option>
    @endforeach
</select>
